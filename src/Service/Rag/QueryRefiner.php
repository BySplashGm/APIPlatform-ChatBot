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
Task: Extract exact technical keywords for vector search (API Platform & Symfony).
Target data: Markdown documentation and PHP functional test files.

STRICT RULES:
1. Output ONLY space-separated keywords.
2. NO conversational text (e.g., "Here are...", "The keywords...").
3. NO fluff words (e.g., example, how to, explain, documentation, code, tutorial, please).
4. MUST include exact PHP class/interface/attribute names related to the concept.
5. Translate all concepts to English.

--- EXAMPLES ---

Query: "Comment créer un state provider personnalisé ?"
Keywords: StateProvider ProviderInterface custom

Query: "How to write a functional test for an API endpoint using ApiTestCase?"
Keywords: ApiTestCase functional test GET POST request

Query: "Comment faire un custom filter sur doctrine ?"
Keywords: FilterInterface ContextAwareFilterInterface Doctrine custom filter

Query: "C'est quoi la sérialisation ?"
Keywords: serialization normalization denormalization Groups attribute

Query: "Give me an example to create an API Platform entity"
Keywords: ApiResource entity attribute resource

--- TASK ---

Query: "$userQuery"
Keywords: 
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
