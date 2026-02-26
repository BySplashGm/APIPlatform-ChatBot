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
Task: Rewrite the user's query into a highly descriptive, semantic sentence for a vector search engine (API Platform & Symfony).

STRICT RULES:
1. Write a complete, grammatically correct sentence or short paragraph.
2. MUST include the specific PHP class names, interfaces, or attributes required (e.g., ApiTestCase, ProviderInterface).
3. State clearly what is being looked for (e.g., "A PHP code example demonstrating...", "Official documentation explaining...").
4. Translate everything to English.
5. NO conversational fluff ("Here is the query"). Start directly with the descriptive sentence.

--- EXAMPLES ---

User: "Comment créer un state provider personnalisé ?"
Query: Official documentation and PHP code examples explaining how to create a custom State Provider by implementing the ProviderInterface.

User: "How to write a functional test for an API endpoint using ApiTestCase?"
Query: A complete PHP code example of a functional test for an API endpoint extending ApiTestCase, including GET and POST requests.

User: "C'est quoi la sérialisation ?"
Query: Explanation of the serialization and deserialization process in API Platform, including the use of normalizers, denormalizers, and serialization Groups attributes.

--- TASK ---

User: "$userQuery"
Query: 
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
