<?php

namespace App\Service\Benchmark;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RagService
{
    private const TIMEOUT = 60;

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        #[Autowire(env: 'RAG_MODEL_NAME')]
        private string $modelName = 'mistral'
    ) {
    }

    public function askQuestion(string $question, string $source): string
    {
        try {
            $vectorStr = $this->getEmbedding($question);

            $context = match($source) {
                'combined' => $this->retrieveCombinedContext($vectorStr),
                default => $this->retrieveSingleSourceContext($source, $vectorStr)
            };

            if (empty($context)) {
                return "I don't have enough information to answer this question.";
            }

            return $this->generateAnswer($question, $context);
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
            'timeout' => 30
        ]);
        return $response->toArray()['embeddings'][0];
    }

    private function retrieveSingleSourceContext(string $source, string $vectorStr): string
    {
        $tableName = 'vector_store_' . $source;
        $sql = "SELECT content FROM $tableName ORDER BY vector <=> '$vectorStr' LIMIT 2";

        $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql);
        return implode("\n---\n", array_column($rows, 'content'));
    }

    private function retrieveCombinedContext(string $vectorStr): string
    {
        $docs = $this->retrieveSingleSourceContext('docs', $vectorStr);
        $code = $this->retrieveSingleSourceContext('code', $vectorStr);

        return "DOCUMENTATION:\n$docs\n\nCODE EXAMPLES:\n$code";
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
                    ['role' => 'user', 'content' => $question]
                ]
            ],
            'timeout' => self::TIMEOUT
        ]);

        return $chatResponse->toArray()['message']['content'] ?? "Generation error";
    }
}