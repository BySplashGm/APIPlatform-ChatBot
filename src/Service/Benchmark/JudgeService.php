<?php

namespace App\Service\Benchmark;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class JudgeService
{
    private const TIMEOUT = 300;

    public function __construct(
        private HttpClientInterface $httpClient
    ) {
    }

    public function judgeAnswer(string $question, string $answer, string $category, string $expected): array
    {
        $judgePrompt = <<<PROMPT
Act as an impartial technical judge evaluating a chatbot specialized in API Platform and Symfony.

QUESTION: "$question"
QUESTION CATEGORY: "$category"
EXPECTED BEHAVIOR: "$expected"
BOT RESPONSE: "$answer"

EVALUATION CONTEXT:
- If category is "trap": The bot MUST decline to answer or say it lacks information (score 5 if it declines, 0 if it invents an answer)
- If category is "basic": Answer should be clear and accurate
- If category is "code": Answer should include relevant code examples
- If category is "advanced": Answer should be technically precise
- If category is "security" or "testing": Answer should address the specific domain

SCORING RULES:
- 5: Perfect answer, follows expected behavior exactly
- 4: Good answer with minor imperfections
- 3: Partial answer, missing some important details
- 2: Weak answer, significant gaps or minor hallucinations
- 1: Poor answer, mostly incorrect or irrelevant
- 0: Complete failure, hallucination, or answering trap questions

Give me a JSON with:
- "score": integer from 0 to 5
- "reason": short explanation in English (max 20 words)

Reply ONLY with the JSON.
PROMPT;

        try {
            $response = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/chat', [
                'json' => [
                    'model' => 'mistral', 
                    'format' => 'json',
                    'stream' => false,
                    'options' => ['temperature' => 0.0],
                    'messages' => [['role' => 'user', 'content' => $judgePrompt]]
                ],
                'timeout' => self::TIMEOUT
            ]);
            
            $data = json_decode($response->toArray()['message']['content'], true);
            return ['score' => $data['score'] ?? 0, 'reason' => $data['reason'] ?? 'Error'];
        } catch (\Exception $e) {
            return ['score' => 0, 'reason' => 'Judge Error: ' . $e->getMessage()];
        }
    }
}
