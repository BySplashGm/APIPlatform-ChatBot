<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsController]
class ChatController
{
    private const EMBEDDING_MODEL = 'nomic-embed-text';
    private const CHAT_MODEL = 'qwen2.5-coder:1.5b';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/chat', methods: ['POST'])]
    public function __invoke(Request $request): StreamedResponse
    {
        $data = $request->toArray();
        $userMessage = end($data['messages'])['content'] ?? '';

        $embResponse = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
            'json' => [
                'model' => self::EMBEDDING_MODEL,
                'input' => $userMessage,
            ],
        ]);
        $vector = $embResponse->toArray()['embeddings'][0];
        $vectorStr = '[' . implode(',', $vector) . ']';

        $conn = $this->entityManager->getConnection();
        $results = $conn->executeQuery("SELECT content, metadata FROM vector_store ORDER BY vector <=> :vectorString LIMIT 3", ['vectorString' => $vectorStr]);

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
            'timeout' => 300, // 5 minutes
            'json' => [
                'model' => self::CHAT_MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage]
                ],
            ],
        ]);

        $client = $this->httpClient;

        $response = new StreamedResponse(function() use ($ollamaResponse, $client) {
            // Désactiver complètement le buffering
            if (function_exists('apache_setenv')) {
                apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', 'Off');
            @ini_set('output_buffering', 'Off');
            
            // Vider tous les buffers existants
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // Activer le flush implicite
            ob_implicit_flush(true);

            $buffer = '';

            foreach ($client->stream($ollamaResponse) as $chunk) {
                if ($chunk->isFirst() || $chunk->isLast()) {
                    continue;
                }

                $buffer .= $chunk->getContent();

                // Parser ligne par ligne
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    if (empty(trim($line))) {
                        continue;
                    }

                    $json = json_decode($line, true);
                    if (isset($json['message']['content'])) {
                        $text = $json['message']['content'];
                        echo $text;
                        
                        // Double flush pour forcer l'envoi
                        if (connection_status() !== CONNECTION_NORMAL) {
                            break 2;
                        }
                        flush();
                    }
                }
            }

            // Reste du buffer
            if (!empty(trim($buffer))) {
                $json = json_decode($buffer, true);
                if (isset($json['message']['content'])) {
                    echo $json['message']['content'];
                    flush();
                }
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}