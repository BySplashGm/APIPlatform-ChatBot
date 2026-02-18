<?php

namespace App\Service\Rag;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class QueryRefiner
{
    // On garde un timeout très court, ça doit être instantané
    private const TIMEOUT = 5;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'OLLAMA_URL')]
        private string $ollamaUrl,
        #[Autowire(env: 'REFINER_MODEL_NAME')]
        private string $modelName
    ) {
    }

    public function refine(string $userQuery): string
    {
        $prompt = <<<PROMPT
You are a smart search optimizer for API Platform.
Convert the user question into a search query optimized for a vector database.

RULES:
1. Keep technical keywords (e.g., "StateProvider", "SearchFilter").
2. IMPORTANT: KEEP ACTION VERBS if the user wants to generate code (e.g., "create", "write", "generate", "example").
3. Remove conversational noise (e.g., "Hello", "Can you tell me", "please").
4. Do NOT output JSON. Just the text.

Example 1:
User: "Can you explain how the security works?"
Output: security mechanism explanation

Example 2:
User: "Write a custom DataPersister for User entity"
Output: write custom DataPersister User entity code example

User: "$userQuery"
Output:
PROMPT;

        try {
            $response = $this->httpClient->request('POST', $this->ollamaUrl . '/api/generate', [
                'json' => [
                    'model' => $this->modelName,
                    'prompt' => $prompt,
                    'stream' => false,
                    'options' => [
                        'temperature' => 0.0,
                        'num_predict' => 64,
                    ],
                ],
                'timeout' => self::TIMEOUT,
            ]);

            $data = $response->toArray();
            $keywords = trim($data['response'] ?? '');

            return empty($keywords) ? $userQuery : $keywords;

        } catch (\Exception $e) {
            return $userQuery;
        }
    }
}
