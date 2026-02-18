<?php

namespace App\Controller;

use App\Service\Rag\QueryRefiner;
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
        private QueryRefiner $queryRefiner,
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

        // 1. Raffiner la requête pour la recherche vectorielle
        $searchKeywords = $this->queryRefiner->refine($userMessage);

        // 2. Récupérer le vecteur et le contexte + sources via RagService
        try {
            $vectorStr = $this->ragService->getEmbedding($searchKeywords);
        } catch (\Exception $e) {
            return new StreamedResponse(function () {
                echo "Erreur lors de la vectorisation.";
            }, 500);
        }

        // Accès aux méthodes internes via reflection ou appel direct — on passe
        // par askQuestion mais en mode streaming on reconstruit le pipeline pour
        // pouvoir streamer les tokens ET ajouter les sources en fin de flux.
        ['context' => $context, 'sources' => $sources] = $this->ragService->retrieveContext($this->chatSource, $vectorStr);

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

        $ollamaResponse = $this->httpClient->request('POST', $this->ollamaUrl . '/api/chat', [
            'buffer' => false,
            'timeout' => 600,
            'json' => [
                'model' => $this->chatModel,
                'options' => [
                    'temperature' => 0.0,
                    'num_ctx' => 4096,
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
                            // Ajouter la section sources en fin de stream
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