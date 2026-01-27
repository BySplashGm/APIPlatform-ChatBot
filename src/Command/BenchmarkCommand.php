<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'app:benchmark', description: 'Run quality tests with different documentation sources')]
class BenchmarkCommand extends Command
{
    private const CSV_FILE = 'benchmark_results.csv';
    private const FIXTURES_FILE = 'liste_fichiers_a_indexer.txt';
    private const TIMEOUT = 300;

    private const QUESTIONS = [
        ['question' => "What is a State Provider?", 'category' => 'basic', 'expected' => 'Should explain State Provider concept'],
        ['question' => "How does API Platform handle serialization?", 'category' => 'basic', 'expected' => 'Should explain serialization mechanism'],
        ['question' => "Give me a code example to create an API Platform entity.", 'category' => 'code', 'expected' => 'Should provide PHP code example with ApiResource'],
        ['question' => "How to implement a custom data provider?", 'category' => 'code', 'expected' => 'Should provide implementation code'],
        ['question' => "How to create a Custom Filter?", 'category' => 'advanced', 'expected' => 'Should provide filter implementation'],
        ['question' => "How to handle validation errors (422)?", 'category' => 'advanced', 'expected' => 'Should explain 422 error handling'],
        ['question' => "How to secure API endpoints?", 'category' => 'security', 'expected' => 'Should explain endpoint security'],
        ['question' => "How to write functional tests for API Platform?", 'category' => 'testing', 'expected' => 'Should explain functional testing'],
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
        $this->addOption('reindex', null, InputOption::VALUE_NONE, 'Force re-indexing (clear and rebuild all data)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('RAG Benchmark - API Platform Documentation');
        $io->text([
            'Testing chatbot quality across 3 documentation sources:',
            '  <fg=cyan>docs</>     - Markdown documentation only',
            '  <fg=yellow>code</>     - PHP functional tests + Fixtures',
            '  <fg=magenta>combined</> - Everything merged',
            ''
        ]);

        $reindex = $input->getOption('reindex');

        if ($reindex) {
            $io->section('Step 1/4: Re-indexing Vector Stores');
            $this->prepareVectorStores($io, $output);
        } else {
            $io->section('Step 1/4: Checking Existing Data');
            $this->checkExistingData($io, $output);
        }

        $filePath = $this->projectDir . '/' . self::CSV_FILE;
        $fp = fopen($filePath, 'a+');

        if (!file_exists($filePath) || filesize($filePath) === 0) {
            fputcsv($fp, ['Date', 'Source', 'Category', 'Question', 'ChatBot Response', 'Score (0-5)', 'Judge Reason', 'Model Used', 'Response Time (ms)']);
        }

        $sources = ['docs', 'code', 'combined'];
        $allResults = [];
        $currentStep = 2;
        
        foreach ($sources as $source) {
            $io->section("Step $currentStep/4: Testing with '$source'");
            $currentStep++;
            
            $sourceResults = [];
            $progressBar = $io->createProgressBar(count(self::QUESTIONS));
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %message%');
            $progressBar->start();
            
            foreach (self::QUESTIONS as $questionData) {
                $shortQuestion = strlen($questionData['question']) > 50 
                    ? substr($questionData['question'], 0, 47) . '...' 
                    : $questionData['question'];
                    
                $progressBar->setMessage("[{$questionData['category']}] $shortQuestion");
                
                $startTime = microtime(true);
                $ragResponse = $this->askRag($questionData['question'], $source);
                $responseTime = round((microtime(true) - $startTime) * 1000);
                
                $scoreData = $this->judgeAnswer($questionData['question'], $ragResponse, $questionData['category'], $questionData['expected']);

                fputcsv($fp, [
                    date('Y-m-d H:i:s'),
                    $source,
                    $questionData['category'],
                    $questionData['question'],
                    $ragResponse,
                    $scoreData['score'],
                    $scoreData['reason'],
                    'mistral',
                    $responseTime
                ]);

                $sourceResults[] = ['score' => $scoreData['score'], 'time' => $responseTime, 'category' => $questionData['category']];
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $io->newLine(2);
            $this->displaySourceResults($io, $sourceResults);
            $allResults[$source] = $sourceResults;
        }

        fclose($fp);
        
        $io->section('Final Statistics');
        $this->displayFinalStats($io, $allResults);
        
        $io->newLine();
        $io->success("Benchmark completed!");
        $io->text([
            "Results saved in: <fg=cyan>$filePath</>",
            "Total tests: <fg=yellow>" . (count($sources) * count(self::QUESTIONS)) . "</>"
        ]);
        
        return Command::SUCCESS;
    }

    private function prepareVectorStores(SymfonyStyle $io, OutputInterface $output): void
    {
        $fixturesPath = $this->projectDir . '/' . self::FIXTURES_FILE;
        $fixtures = [];
        
        if (file_exists($fixturesPath)) {
            $fixtures = file($fixturesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $io->note(sprintf("Loaded %d extra fixture files from %s", count($fixtures), self::FIXTURES_FILE));
        }

        $sources = [
            'docs' => [
                'paths' => ['docs/'],
                'description' => 'Documentation (Markdown)'
            ],
            'code' => [
                'paths' => array_merge(['core/tests/Functional/'], $fixtures),
                'description' => 'Code (Tests + Fixtures)'
            ],
            'combined' => [
                'paths' => array_merge(['docs/', 'core/tests/Functional/'], $fixtures),
                'description' => 'Combined (Docs + Tests + Fixtures)'
            ]
        ];

        foreach ($sources as $target => $config) {
            $io->text("<fg=cyan>Target: {$target} ({$config['description']})</>");
            
            $tableName = 'vector_store_' . $target;
            $this->entityManager->getConnection()->executeStatement("TRUNCATE TABLE $tableName");
            
            $progressBar = new ProgressBar($output, count($config['paths']));
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
            $progressBar->start();

            foreach ($config['paths'] as $path) {
                $fullPath = (str_starts_with($path, '/') || str_contains($path, ':')) 
                    ? $path 
                    : $this->projectDir . '/' . $path;

                if (!file_exists($fullPath)) {
                    $progressBar->advance();
                    continue;
                }
                
                $progressBar->setMessage("Indexing " . basename($path));

                $process = new Process([
                    'php', 'bin/console', 'app:ingest', $fullPath, '--target=' . $target
                ]);
                
                $process->setTimeout(600);
                $process->run();
                
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $io->newLine();
            
            $count = $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM $tableName");
            $io->text("  -> <fg=green>[OK] Table '$tableName' contains $count chunks</>\n");
        }
    }

    private function checkExistingData(SymfonyStyle $io, OutputInterface $output): void
    {
        $conn = $this->entityManager->getConnection();
        $tableData = [];
        $allReady = true;
        
        foreach (['docs', 'code', 'combined'] as $table) {
            $count = $conn->fetchOne("SELECT COUNT(*) FROM vector_store_$table");
            $tableData[] = ["vector_store_$table", $count, $count > 0 ? 'OK' : 'EMPTY'];
            if ($count == 0) $allReady = false;
        }
        
        (new Table($output))->setHeaders(['Table', 'Chunks', 'Status'])->setRows($tableData)->render();
        
        if (!$allReady) {
            throw new \RuntimeException("Some tables are empty. Run with --reindex first.");
        }
    }
    
    private function displaySourceResults(SymfonyStyle $io, array $results): void
    {
        $avgScore = round(array_sum(array_column($results, 'score')) / count($results), 2);
        $scoreColor = $avgScore >= 4 ? 'green' : ($avgScore >= 2.5 ? 'yellow' : 'red');
        $io->text("  Average Score: <fg=$scoreColor>$avgScore/5</>");
    }

    private function displayFinalStats(SymfonyStyle $io, array $allResults): void
    {
        $tableData = [];
        
        foreach ($allResults as $source => $results) {
            $avgScore = round(array_sum(array_column($results, 'score')) / count($results), 2);
            $avgTime = round(array_sum(array_column($results, 'time')) / count($results), 0);
            $tableData[] = [$source, count($results), "$avgScore/5", "{$avgTime}ms"];
        }
        
        (new Table($io))->setHeaders(['Source', 'Tests', 'Avg Score', 'Time'])->setRows($tableData)->render();
    }

    private function askRag(string $question, string $source): string
    {
        $tableName = 'vector_store_' . $source;
        
        try {
            $emb = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
                'json' => ['model' => 'nomic-embed-text', 'input' => $question], 
                'timeout' => self::TIMEOUT
            ]);
            $vectorStr = '[' . implode(',', $emb->toArray()['embeddings'][0]) . ']';

            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT content FROM $tableName ORDER BY vector <=> '$vectorStr' LIMIT 3"
            );
            
            if (empty($rows)) return "No documents found.";
            
            $context = implode("\n---\n", array_column($rows, 'content'));

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
            
            $data = json_decode($response->toArray()['message']['content'], true);
            return ['score' => $data['score'] ?? 0, 'reason' => $data['reason'] ?? 'Error'];
        } catch (\Exception $e) {
            return ['score' => 0, 'reason' => 'Judge Error'];
        }
    }
}