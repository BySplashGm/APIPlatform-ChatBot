<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[AsController]
class ChatController
{
    private const EMBEDDING_MODEL = 'nomic-embed-text';
    private const CHAT_MODEL = 'mistral';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/chat', methods: ['POST'])]
    public function __invoke(Request $request): StreamedResponse
    {
        set_time_limit(0);

        $data = $request->toArray();
        $userMessage = end($data['messages'])['content'] ?? '';

        try {
            $embResponse = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
                'json' => [
                    'model' => self::EMBEDDING_MODEL,
                    'input' => $userMessage,
                ],
            ]);
            $vector = $embResponse->toArray()['embeddings'][0];
        } catch (\Exception $e) {
            return new StreamedResponse(function() { echo "Erreur lors de la vectorisation."; }, 500);
        }

        $vectorStr = '[' . implode(',', $vector) . ']';

        $conn = $this->entityManager->getConnection();
        $results = $conn->executeQuery("SELECT content, metadata FROM vector_store_docs ORDER BY vector <=> :vectorString LIMIT 3", ['vectorString' => $vectorStr]);

        $context = "";
        foreach ($results->iterateAssociative() as $row) {
            $meta = json_decode($row['metadata'], true);
            $file = $meta['filename'] ?? 'Doc inconnue';
            $context .= "--- Extrait de : $file ---\n" . $row['content'] . "\n\n";
        }

        $systemPrompt = <<<PROMPT
            Tu es un assistant technique strict dédié à la documentation API Platform.
            Ton rôle est de répondre aux questions des développeurs en utilisant UNIQUEMENT le contexte fourni ci-dessous.

            RÈGLES ABSOLUES :
            1. Utilise SEULEMENT les informations présentes dans le CONTEXTE ci-dessous.
            2. Si la réponse n'est pas dans le contexte, dis : "Désolé, je ne trouve pas cette information dans la documentation fournie."
            3. N'invente JAMAIS de code ou d'explications qui ne sont pas dans le texte source.
            4. Ne réponds pas aux questions générales (météo, cuisine, histoire, etc.).
            5. Sois concis et technique.

            CONTEXTE :
            $context
            PROMPT;

        $ollamaResponse = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/chat', [
            'buffer' => false,
            'timeout' => 600,
            'json' => [
                'model' => self::CHAT_MODEL,
                'options' => [
                    'temperature' => 0.0,
                    'num_ctx' => 4096
                ],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage]
                ],
            ],
        ]);

        $client = $this->httpClient;

        $response = new StreamedResponse(function() use ($ollamaResponse, $client) {
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

                        if (trim($line) === '') continue;

                        $json = json_decode($line, true);
                        
                        if (is_array($json) && isset($json['message']['content'])) {
                            echo $json['message']['content'];
                            flush();
                        }
                        
                        if (isset($json['done']) && $json['done'] === true) {
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