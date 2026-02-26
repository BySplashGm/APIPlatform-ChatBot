<?php

namespace App\Controller;

use App\Service\Rag\RagService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[AsController]
class ChatController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private RagService $ragService,
        #[Autowire(env: 'OLLAMA_URL')]
        private string $ollamaUrl,
        #[Autowire(env: 'RAG_MODEL_NAME')]
        private string $chatModel,
        #[Autowire(env: 'CHAT_SOURCE')]
        private string $chatSource = 'combined'
    ) {}

    #[Route('/chat', methods: ['POST'])]
    public function __invoke(Request $request): StreamedResponse
    {
        set_time_limit(0);

        $data = $request->toArray();
        $userMessage = end($data['messages'])['content'] ?? '';

        try {
            ['context' => $context, 'sources' => $sources] = $this->ragService->prepareRagContext($userMessage, $this->chatSource);
        } catch (\Exception $e) {
            return new StreamedResponse(function () use ($e) {
                echo "Erreur: " . $e->getMessage();
            }, 500);
        }

        $systemPrompt = $this->ragService->buildSystemPrompt($userMessage, $context);

        $ollamaResponse = $this->httpClient->request('POST', $this->ollamaUrl . '/api/chat', [
            'buffer' => false,
            'timeout' => 600,
            'json' => [
                'model' => $this->chatModel,
                'options' => [
                    'temperature' => 0.0,
                    'num_ctx' => 4096,
                    'num_predict' => 512,
                    'top_k' => 20,
                    'top_p' => 0.9,
                ],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ],
        ]);

        $client = $this->httpClient;
        $sourcesSection = $this->ragService->formatSources($sources);

        $response = new StreamedResponse(function () use ($ollamaResponse, $client, $sourcesSection) {
            if (function_exists('apache_setenv')) {
                apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', 'Off');
            @ini_set('output_buffering', 'Off');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_implicit_flush(true);

            $buffer = '';

            try {
                foreach ($client->stream($ollamaResponse) as $chunk) {
                    if ($chunk->isTimeout()) {
                        continue;
                    }

                    try {
                        $content = $chunk->getContent();
                    } catch (TransportExceptionInterface $e) {
                        break;
                    }

                    $buffer .= $content;

                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);

                        if (trim($line) === '') {
                            continue;
                        }

                        $json = json_decode($line, true);

                        if (is_array($json) && isset($json['message']['content'])) {
                            echo $json['message']['content'];
                            flush();
                        }

                        if (isset($json['done']) && $json['done'] === true) {
                            if ($sourcesSection !== '') {
                                echo "\n\n---\n" . $sourcesSection;
                                flush();
                            }
                            break 2;
                        }
                    }

                    if (connection_aborted()) {
                        break;
                    }
                }
            } catch (\Exception $e) {
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}