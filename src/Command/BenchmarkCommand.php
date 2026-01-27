<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:benchmark', description: 'Run quality tests with different documentation sources')]
class BenchmarkCommand extends Command
{
    private const CSV_FILE = 'benchmark_results.csv';
    private const TIMEOUT = 300;

    private const QUESTIONS = [
        // Basic (2 questions essentielles)
        ['question' => "What is a State Provider?", 'category' => 'basic', 'expected' => 'Should explain State Provider concept'],
        ['question' => "How does API Platform handle serialization?", 'category' => 'basic', 'expected' => 'Should explain serialization mechanism'],
        
        // Code (2 questions)
        ['question' => "Give me a code example to create an API Platform entity.", 'category' => 'code', 'expected' => 'Should provide PHP code example with ApiResource'],
        ['question' => "How to implement a custom data provider?", 'category' => 'code', 'expected' => 'Should provide implementation code'],
        
        // Advanced (2 questions)
        ['question' => "How to create a Custom Filter?", 'category' => 'advanced', 'expected' => 'Should provide filter implementation'],
        ['question' => "How to handle validation errors (422)?", 'category' => 'advanced', 'expected' => 'Should explain 422 error handling'],
        
        // Security (1 question)
        ['question' => "How to secure API endpoints?", 'category' => 'security', 'expected' => 'Should explain endpoint security'],
        
        // Testing (1 question)
        ['question' => "How to write functional tests for API Platform?", 'category' => 'testing', 'expected' => 'Should explain functional testing'],
        
        // Trap (2 questions - IMPORTANT pour tester le gardien)
        ['question' => "What is the capital of Switzerland?", 'category' => 'trap', 'expected' => 'Should decline to answer or say it lacks information'],
        ['question' => "How to cook a pizza?", 'category' => 'trap', 'expected' => 'Should decline to answer'],
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('skip-ingest', null, InputOption::VALUE_NONE, 'Skip re-indexing (use existing data)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Banner
        $io->title('RAG Benchmark - API Platform Documentation');
        $io->text([
            'Testing chatbot quality across 3 documentation sources:',
            '  <fg=cyan>docs</> - Markdown documentation only',
            '  <fg=yellow>code</> - PHP functional tests only',
            '  <fg=magenta>combined</> - Both sources merged',
            ''
        ]);

        $skipIngest = $input->getOption('skip-ingest');

        // Vérification des tables
        if ($skipIngest) {
            $conn = $this->entityManager->getConnection();
            $allTablesReady = true;
            
            $io->section('Checking existing data');
            $tableData = [];
            
            foreach (['docs', 'code', 'combined'] as $table) {
                $count = $conn->fetchOne("SELECT COUNT(*) FROM vector_store_$table");
                $tableData[] = ["vector_store_$table", $count, $count > 0 ? 'OK' : 'EMPTY'];
                if ($count == 0) {
                    $allTablesReady = false;
                }
            }
            
            $table = new Table($output);
            $table->setHeaders(['Table', 'Chunks', 'Status'])
                  ->setRows($tableData);
            $table->render();
            
            if (!$allTablesReady) {
                $io->error("Some tables are empty. Run without --skip-ingest first.");
                return Command::FAILURE;
            }
            
            $io->success("All tables ready. Skipping ingestion.");
        } else {
            $io->section('Step 1/4: Preparing Vector Stores');
            $this->prepareVectorStores($io, $output);
        }

        // Préparation du CSV
        $filePath = $this->projectDir . '/' . self::CSV_FILE;
        $fileExists = file_exists($filePath);
        
        $fp = fopen($filePath, 'a+');

        if (!$fileExists || filesize($filePath) === 0) {
            fputcsv($fp, ['Date', 'Source', 'Category', 'Question', 'ChatBot Response', 'Score (0-5)', 'Judge Reason', 'Model Used', 'Response Time (ms)']);
        }

        // Tests par source
        $sources = ['docs', 'code', 'combined'];
        $allResults = [];
        $currentStep = $skipIngest ? 1 : 2;
        
        foreach ($sources as $source) {
            $io->section("Step $currentStep/4: Testing with '$source'");
            $currentStep++;
            
            $sourceResults = [];
            $progressBar = $io->createProgressBar(count(self::QUESTIONS));
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %message%');
            $progressBar->setMessage('Starting...');
            $progressBar->start();
            
            foreach (self::QUESTIONS as $qIndex => $questionData) {
                $question = $questionData['question'];
                $category = $questionData['category'];
                $expected = $questionData['expected'];
                
                $shortQuestion = strlen($question) > 50 ? substr($question, 0, 47) . '...' : $question;
                $progressBar->setMessage("[$category] $shortQuestion");
                
                $startTime = microtime(true);
                $ragResponse = $this->askRag($question, $source);
                $responseTime = round((microtime(true) - $startTime) * 1000);
                
                $scoreData = $this->judgeAnswer($question, $ragResponse, $category, $expected);

                fputcsv($fp, [
                    date('Y-m-d H:i:s'),
                    $source,
                    $category,
                    $question,
                    $ragResponse,
                    $scoreData['score'],
                    $scoreData['reason'],
                    'mistral',
                    $responseTime
                ]);

                $sourceResults[] = [
                    'question' => $question,
                    'category' => $category,
                    'score' => $scoreData['score'],
                    'reason' => $scoreData['reason'],
                    'time' => $responseTime
                ];
                
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $io->newLine(2);
            
            // Afficher les résultats de cette source
            $this->displaySourceResults($io, $source, $sourceResults);
            
            $allResults[$source] = $sourceResults;
        }

        fclose($fp);
        
        // Statistiques finales
        $io->section('Final Statistics');
        $this->displayFinalStats($io, $allResults);
        
        $io->newLine();
        $io->success("Benchmark completed!");
        $io->text([
            "Results saved in: <fg=cyan>$filePath</>",
            "Total tests: <fg=yellow>" . (count($sources) * count(self::QUESTIONS)) . "</>",
            "Average response time: <fg=yellow>" . $this->calculateAvgTime($allResults) . "ms</>"
        ]);
        
        return Command::SUCCESS;
    }

    private function prepareVectorStores(SymfonyStyle $io, OutputInterface $output): void
    {
        $sources = [
            'docs' => [
                'paths' => ['docs/'],
                'description' => 'Documentation (Markdown)'
            ],
            'code' => [
                'paths' => ['core/tests/Functional/'],
                'description' => 'Code (PHP tests)'
            ],
            'combined' => [
                'paths' => ['docs/', 'core/tests/Functional/'],
                'description' => 'Combined (Docs + Code)'
            ]
        ];

        foreach ($sources as $target => $config) {
            $io->text("<fg=cyan>{$config['description']}</>");
            
            $conn = $this->entityManager->getConnection();
            $tableName = 'vector_store_' . $target;
            $conn->executeStatement("TRUNCATE TABLE $tableName");
            
            foreach ($config['paths'] as $path) {
                if (!is_dir($this->projectDir . '/' . $path)) {
                    $io->warning("  Path not found: $path (skipping)");
                    continue;
                }
                
                $io->text("  -> Indexing: <fg=yellow>$path</>");
                
                $process = \Symfony\Component\Process\Process::fromShellCommandline(
                    sprintf('php bin/console app:ingest %s --target=%s', escapeshellarg($path), $target),
                    $this->projectDir
                );
                
                $process->setTimeout(600);
                $process->run(function ($type, $buffer) use ($output) {
                    // Afficher seulement les lignes importantes
                    if (strpos($buffer, 'OK') !== false || strpos($buffer, 'chunks)') !== false) {
                        $output->write('     ' . $buffer);
                    }
                });

                if (!$process->isSuccessful()) {
                    throw new \RuntimeException("Ingestion failed for $path");
                }
            }
            
            $count = $conn->fetchOne("SELECT COUNT(*) FROM $tableName");
            $io->text("  <fg=green>[OK] Ready with $count chunks</>\n");
        }
    }

    private function displaySourceResults(SymfonyStyle $io, string $source, array $results): void
    {
        $avgScore = round(array_sum(array_column($results, 'score')) / count($results), 2);
        $avgTime = round(array_sum(array_column($results, 'time')) / count($results), 0);
        
        $scoreColor = $avgScore >= 4 ? 'green' : ($avgScore >= 2.5 ? 'yellow' : 'red');
        
        $io->text([
            "  Average Score: <fg=$scoreColor>" . $avgScore . "/5</>",
            "  Average Time: <fg=cyan>" . $avgTime . "ms</>",
        ]);
        
        // Afficher les résultats par catégorie
        $byCategory = [];
        foreach ($results as $result) {
            $cat = $result['category'];
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = ['scores' => [], 'count' => 0];
            }
            $byCategory[$cat]['scores'][] = $result['score'];
            $byCategory[$cat]['count']++;
        }
        
        $io->text("\n  By Category:");
        foreach ($byCategory as $cat => $data) {
            $avg = round(array_sum($data['scores']) / $data['count'], 1);
            $color = $avg >= 4 ? 'green' : ($avg >= 2.5 ? 'yellow' : 'red');
            $io->text("    <fg=white>" . str_pad($cat, 10) . "</>: <fg=$color>$avg/5</> ({$data['count']} tests)");
        }
    }

    private function displayFinalStats(SymfonyStyle $io, array $allResults): void
    {
        $tableData = [];
        
        foreach ($allResults as $source => $results) {
            $avgScore = round(array_sum(array_column($results, 'score')) / count($results), 2);
            $avgTime = round(array_sum(array_column($results, 'time')) / count($results), 0);
            
            $scoreColor = $avgScore >= 4 ? 'green' : ($avgScore >= 2.5 ? 'yellow' : 'red');
            
            $tableData[] = [
                $source,
                count($results),
                "<fg=$scoreColor>$avgScore/5</>",
                "{$avgTime}ms"
            ];
        }
        
        $table = new Table($io);
        $table->setHeaders(['Source', 'Tests', 'Avg Score', 'Avg Time'])
              ->setRows($tableData);
        $table->render();
    }

    private function calculateAvgTime(array $allResults): int
    {
        $totalTime = 0;
        $totalTests = 0;
        
        foreach ($allResults as $results) {
            foreach ($results as $result) {
                $totalTime += $result['time'];
                $totalTests++;
            }
        }
        
        return $totalTests > 0 ? round($totalTime / $totalTests) : 0;
    }

    private function askRag(string $question, string $source): string
    {
        $tableName = 'vector_store_' . $source;

        try {
            // A. Embedding
            $embResponse = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
                'json' => ['model' => 'nomic-embed-text', 'input' => $question],
                'timeout' => self::TIMEOUT
            ]);
            $vector = $embResponse->toArray()['embeddings'][0];
            $vectorStr = '[' . implode(',', $vector) . ']';

            // B. SQL Search
            $conn = $this->entityManager->getConnection();
            $rows = $conn->fetchAllAssociative("SELECT content FROM $tableName ORDER BY vector <=> '$vectorStr' LIMIT 3");
            
            if (empty($rows)) {
                return "No documents found.";
            }

            $context = implode("\n---\n", array_column($rows, 'content'));

            // C. Generation
            $systemPrompt = <<<PROMPT
You are an expert AI assistant specialized in API Platform and Symfony.

STRICT RULES:
1. Answer ONLY based on the provided context below
2. If the context doesn't contain the answer, respond: "I don't have enough information in the documentation to answer this question."
3. Do NOT invent or hallucinate information
4. Provide code examples when available in the context
5. Be concise and technical
6. If asked about topics unrelated to API Platform/Symfony, politely decline

CONTEXT:
$context
PROMPT;
            
            $chatResponse = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/chat', [
                'json' => [
                    'model' => 'mistral',
                    'stream' => false,
                    'options' => ['temperature' => 0.0],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $question]
                    ]
                ],
                'timeout' => self::TIMEOUT
            ]);

            return $chatResponse->toArray()['message']['content'] ?? "Generation error";
        } catch (\Exception $e) {
            return "Technical error: " . $e->getMessage();
        }
    }

    private function judgeAnswer(string $question, string $answer, string $category, string $expected): array
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

            $content = $response->toArray()['message']['content'];
            
            // Nettoyer le JSON
            $content = trim($content);
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $content = $matches[0];
            }
            
            $decoded = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['score']) || !isset($decoded['reason'])) {
                return ['score' => 0, 'reason' => 'JSON parse error'];
            }

            return [
                'score' => max(0, min(5, (int)$decoded['score'])),
                'reason' => substr($decoded['reason'], 0, 100)
            ];
        } catch (\Exception $e) {
            return ['score' => 0, 'reason' => 'Judge error: ' . $e->getMessage()];
        }
    }
}