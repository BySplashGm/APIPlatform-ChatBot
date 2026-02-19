<?php

namespace App\Service\Rag;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class QueryRefiner
{
    private const TIMEOUT = 15;

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
Role: Technical Keyword Extractor.
Task: Add English search modifiers to the user's query.

CRITICAL RULES:
1. NEVER delete or modify technical terms from the input (even if they look misspelled like "stateprovider" or "apiresource").
2. Add English action keywords (e.g., "create example php code implementation" or "documentation explanation").
3. Output ONLY the English keywords. Do not repeat the user's original query.

Input: "$userQuery"
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
                        'num_predict' => 30,
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
