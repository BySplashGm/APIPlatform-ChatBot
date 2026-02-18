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
Task: Extract technical keywords from the user query for a search engine.
Target: API Platform / Symfony documentation (English).

INSTRUCTIONS:
1. Translate the intent into ENGLISH technical terms.
2. Keep specific class names as is (e.g. "StateProvider", "User").
3. Output ONLY the keywords separated by spaces.

Examples:
Input: "Comment créer un stateprovider ?"
Output: create implement custom StateProvider interface

Input: "C'est quoi un data persister ?"
Output: DataPersister definition usage explanation

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
