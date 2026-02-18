<?php

namespace App\Service\Rag;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RagService
{
    private const TIMEOUT = 60;

    /** Sources autorisées — toute valeur hors liste est rejetée */
    private const ALLOWED_SOURCES = ['docs', 'code'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private QueryRefiner $queryRefiner,
        #[Autowire(env: 'RAG_MODEL_NAME')]
        private string $modelName = 'mistral'
    ) {
    }

    public function askQuestion(string $question, string $source): string
    {
        try {
            $searchKeywords = $this->queryRefiner->refine($question);

            $vectorStr = $this->getEmbedding($searchKeywords);

            ['context' => $context, 'sources' => $sources] = $this->retrieveContext($source, $vectorStr);

            if (empty($context)) {
                return "I don't have enough information.";
            }

            $answer = $this->generateAnswer($question, $context);

            $sourcesSection = $this->formatSourcesSection($sources);

            return $sourcesSection !== '' ? $answer . "\n\n---\n" . $sourcesSection : $answer;

        } catch (\Exception $e) {
            return "Technical error: " . $e->getMessage();
        }
    }

    public function getEmbedding(string $text): string
    {
        $vector = $this->getEmbeddingVector($text);
        return '[' . implode(',', $vector) . ']';
    }

    public function getEmbeddingVector(string $text): array
    {
        $response = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
            'json' => ['model' => 'nomic-embed-text', 'input' => $text],
            'timeout' => 30,
        ]);
        return $response->toArray()['embeddings'][0];
    }

    /**
     * Point d'entrée public pour récupérer contexte + sources selon la source choisie.
     *
     * @return array{context: string, sources: array<string, string>}
     */
    public function retrieveContext(string $source, string $vectorStr): array
    {
        return match ($source) {
            'combined' => $this->retrieveCombinedContext($vectorStr),
            default    => $this->retrieveSingleSourceContext($source, $vectorStr),
        };
    }

    /**
     * Formate le tableau [filepath => url] en section Markdown lisible.
     *
     * @param array<string, string> $sources
     */
    public function formatSources(array $sources): string
    {
        return $this->formatSourcesSection($sources);
    }

    /**
     * @return array{context: string, sources: array<string, string>}
     *   'sources' est un tableau dédupliqué [filepath => url]
     */
    private function retrieveSingleSourceContext(string $source, string $vectorStr): array
    {
        if (!in_array($source, self::ALLOWED_SOURCES, true)) {
            throw new \InvalidArgumentException(sprintf('Source invalide : "%s".', $source));
        }

        if (!preg_match('/^\[[\d.,\s\-]+\]$/', $vectorStr)) {
            throw new \InvalidArgumentException('Format de vecteur invalide.');
        }

        $tableName = 'vector_store_' . $source;
        $sql = "SELECT content, metadata FROM $tableName ORDER BY vector <=> '$vectorStr' LIMIT 5";

        $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql);

        $context = implode("\n\n--- DOCUMENT FRAGMENT ---\n\n", array_column($rows, 'content'));

        $sources = [];
        foreach ($rows as $row) {
            $meta = json_decode($row['metadata'] ?? '{}', true);
            $filename = $meta['filename'] ?? null;
            if ($filename !== null && !isset($sources[$filename])) {
                $sources[$filename] = $this->generateSourceUrl($filename, $source);
            }
        }

        return ['context' => $context, 'sources' => $sources];
    }

    /**
     * @return array{context: string, sources: array<string, string>}
     */
    private function retrieveCombinedContext(string $vectorStr): array
    {
        ['context' => $docsContext, 'sources' => $docsSources] = $this->retrieveSingleSourceContext('docs', $vectorStr);
        ['context' => $codeContext, 'sources' => $codeSources] = $this->retrieveSingleSourceContext('code', $vectorStr);

        $context = "DOCUMENTATION:\n$docsContext\n\nCODE EXAMPLES:\n$codeContext";
        $sources = array_merge($docsSources, $codeSources);

        return ['context' => $context, 'sources' => $sources];
    }

    /**
     * Transforme un chemin de fichier en URL exploitable.
     * Pour 'docs' : URL officielle API Platform.
     */
    private function generateSourceUrl(string $filepath, string $type): string
    {
        if ($type === 'docs') {
            $path = preg_replace('#^(docs/|pages/)#', '', $filepath);
            $path = preg_replace('#\.(mdx?|html?)$#', '', $path);
            $path = trim($path, '/');

            return 'https://api-platform.com/docs/' . $path . '/';
        }

        return 'https://github.com/api-platform/core/blob/main/tests/Functional/' . ltrim($filepath, '/');
    }

    /**
     * @param array<string, string> $sources
     */
    private function formatSourcesSection(array $sources): string
    {
        if (empty($sources)) {
            return '';
        }

        $lines = ['**Sources :**'];
        foreach ($sources as $filepath => $url) {
            $title = pathinfo($filepath, PATHINFO_FILENAME);
            $lines[] = "- [$title]($url)";
        }

        return implode("\n", $lines);
    }

    private function generateAnswer(string $question, string $context): string
    {
        $systemPrompt = <<<PROMPT
You are an expert AI assistant specialized in API Platform and Symfony.

STRICT RULES:
1. Answer ONLY based on the provided context below
2. If the context doesn't contain the answer, respond: "I don't have enough information in the documentation to answer this question."
3. Do NOT invent or hallucinate information
4. Provide code examples when available in the context
5. Be concise and technical
6. If asked about topics unrelated to API Platform/Symfony, politely decline

CONTEXT:
$context
PROMPT;

        $chatResponse = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/chat', [
            'json' => [
                'model' => $this->modelName,
                'stream' => false,
                'options' => [
                    'temperature' => 0.0,
                    'num_ctx' => 4096,
                    'num_predict' => 256,
                    'top_k' => 20,
                    'top_p' => 0.9,
                ],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $question],
                ],
            ],
            'timeout' => self::TIMEOUT,
        ]);

        return $chatResponse->toArray()['message']['content'] ?? "Generation error";
    }
}
