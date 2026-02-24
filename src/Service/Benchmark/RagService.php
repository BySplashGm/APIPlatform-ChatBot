<?php

namespace App\Service\Benchmark;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RagService
{
    private const TIMEOUT = 300;

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager
    ) {
    }

    public function askQuestion(string $question, string $source): string
    {
        $tableName = 'vector_store_' . $source;
        
        try {
            $vectorStr = $this->getEmbedding($question);

            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT content FROM $tableName ORDER BY vector <=> '$vectorStr' LIMIT 3"
            );
            
            if (empty($rows)) {
                return "No documents found.";
            }
            
            $context = implode("\n---\n", array_column($rows, 'content'));

            return $this->generateAnswer($question, $context);
        } catch (\Exception $e) { 
            return "Technical error: " . $e->getMessage(); 
        }
    }

    public function getEmbedding(string $text): string
    {
        $response = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
            'json' => ['model' => 'nomic-embed-text', 'input' => $text], 
            'timeout' => self::TIMEOUT
        ]);
        
        $embedding = $response->toArray()['embeddings'][0];
        return '[' . implode(',', $embedding) . ']';
    }

    public function getEmbeddingVector(string $text): array
    {
        $response = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
            'json' => ['model' => 'nomic-embed-text', 'input' => $text], 
            'timeout' => 30
        ]);
        
        return $response->toArray()['embeddings'][0];
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
                'model' => 'mistral', 
                'stream' => false, 
                'options' => ['temperature' => 0.0],
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
