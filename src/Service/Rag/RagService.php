<?php

namespace App\Service\Rag;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RagService
{
    private const TIMEOUT = 120;

    private const ALLOWED_SOURCES = ['docs', 'code'];

    private const OLLAMA_URL = 'http://127.0.0.1:11434';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private QueryRefiner $queryRefiner,
        #[Autowire(env: 'RAG_MODEL_NAME')]
        private string $modelName
    ) {
    }

    /**
     * Prépare le contexte RAG (refinement, embedding, retrieval)
     * @return array{context: string, sources: array, keywords: string}
     */
    public function prepareRagContext(string $question, string $source): array
    {
        $searchKeywords = $this->queryRefiner->refine($question);

        if (empty(trim($searchKeywords))) {
            $searchKeywords = $question;
        }

        // DEBUG — à supprimer
        file_put_contents(__DIR__.'/../../../var/log/debug_rag_search_keywords.txt', $searchKeywords);

        $vectorStr = $this->getEmbedding($searchKeywords);

        ['context' => $context, 'sources' => $sources] = $this->retrieveContext($source, $vectorStr);

        return ['context' => $context, 'sources' => $sources, 'keywords' => $searchKeywords];
    }

    public function askQuestion(string $question, string $source): string
    {
        try {
            ['context' => $context, 'sources' => $sources] = $this->prepareRagContext($question, $source);

            if (empty($context)) {
                return "I don't have enough information in the documentation to answer this question.";
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
        if (empty(trim($text))) {
            throw new \InvalidArgumentException("Impossible de générer un vecteur : le texte est vide.");
        }

        $response = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
            'json' => ['model' => 'nomic-embed-text', 'input' => $text],
            'timeout' => 30,
        ]);
        
        $data = $response->toArray();

        if (!isset($data['embeddings'][0])) {
             throw new \RuntimeException("Ollama API Error: No embeddings returned.");
        }

        return $data['embeddings'][0];
    }

    public function retrieveContext(string $source, string $vectorStr): array
    {
        return match ($source) {
            'combined' => $this->retrieveCombinedContext($vectorStr),
            default    => $this->retrieveSingleSourceContext($source, $vectorStr),
        };
    }

    private function retrieveSingleSourceContext(string $source, string $vectorStr): array
    {
        if (!in_array($source, self::ALLOWED_SOURCES, true)) {
            throw new \InvalidArgumentException(sprintf('Source invalide : "%s".', $source));
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

    private function retrieveCombinedContext(string $vectorStr): array
    {
        ['context' => $docsContext, 'sources' => $docsSources] = $this->retrieveSingleSourceContext('docs', $vectorStr);
        ['context' => $codeContext, 'sources' => $codeSources] = $this->retrieveSingleSourceContext('code', $vectorStr);

        $context = "DOCUMENTATION:\n$docsContext\n\nCODE EXAMPLES:\n$codeContext";
        $sources = array_merge($docsSources, $codeSources);

        return ['context' => $context, 'sources' => $sources];
    }

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

    public function formatSources(array $sources): string
    {
        return $this->formatSourcesSection($sources);
    }

    private function formatSourcesSection(array $sources): string
    {
        if (empty($sources)) {
            return '';
        }
        
        $sources = array_unique($sources);

        $lines = ['**Sources :**'];
        foreach ($sources as $filepath => $url) {
            $title = pathinfo($filepath, PATHINFO_FILENAME);
            $lines[] = "- [$title]($url)";
        }

        return implode("\n", $lines);
    }

    public function buildSystemPrompt(string $question, string $context): string
    {
        if (strlen($context) > 16000) {
            $context = substr($context, 0, 16000) . "\n... [TRUNCATED]";
        }

        // DEBUG - à supprimer
        file_put_contents(__DIR__.'/../../../var/log/debug_rag_context.txt', $context);

        $systemPrompt = <<<PROMPT
You are an expert AI assistant specialized in API Platform and Symfony.

STRICT RULES:
1. Answer ONLY based on the provided context below.
2. If the context doesn't contain the answer, respond: "I don't have enough information in the documentation provided to answer this."
3. Do NOT invent or hallucinate information.
4. Provide code examples when available in the context.
5. Be concise and technical.
6. Do NOT answer off-topic questions (weather, cooking, history, etc.).

Question: $question

CONTEXT:
$context
PROMPT;

        return $systemPrompt;
    }

    private function generateAnswer(string $question, string $context): string
    {
        $systemPrompt = $this->buildSystemPrompt($question, $context);

        $chatResponse = $this->httpClient->request('POST', self::OLLAMA_URL . '/api/chat', [
            'json' => [
                'model' => $this->modelName,
                'stream' => false,
                'options' => [
                    'temperature' => 0.0,
                    'num_ctx' => 4096,
                    'num_predict' => 512,
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