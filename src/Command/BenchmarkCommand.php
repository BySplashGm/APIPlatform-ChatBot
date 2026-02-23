<?php

namespace App\Command;

use App\Service\Benchmark\CoherenceAnalyzer;
use App\Service\Benchmark\JudgeService;
use App\Service\Benchmark\ResultsManager;
use App\Service\Benchmark\TestGenerator\BiasTestGenerator;
use App\Service\Benchmark\TestGenerator\ContextNoiseTestGenerator;
use App\Service\Benchmark\TestGenerator\RobustnessTestGenerator;
use App\Service\Benchmark\TestGenerator\SecurityTestGenerator;
use App\Service\Rag\RagService;
use App\Service\Rag\VectorStoreManager;
use App\ValueObject\DetailedTestResult;
use App\ValueObject\TestQuestion;
use App\ValueObject\TestResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:benchmark', description: 'Run quality tests with different documentation sources')]
class BenchmarkCommand extends Command
{
    private const CSV_FILE = 'benchmark_results.csv';

    private const QUESTIONS = [
        // --- BASIC ---
        [
            'question' => "What is the exact role of a StateProvider in API Platform v3?", 
            'category' => 'basic', 
            'expected' => 'Should explain that a StateProvider is responsible for retrieving data and mention ProviderInterface.'
        ],
        [
            'question' => "How does API Platform use serialization groups to filter properties?", 
            'category' => 'basic', 
            'expected' => 'Should explain normalization/denormalization contexts and the #[Groups] attribute.'
        ],
        
        // --- CODE ---
        [
            'question' => "Show me a PHP code example to expose a standard PHP class as a resource using the #[ApiResource] attribute.", 
            'category' => 'code', 
            'expected' => 'Must provide valid PHP code with a class annotated or attributed with #[ApiResource].'
        ],
        [
            'question' => "Write a complete PHP example of a custom StateProvider implementing ProviderInterface.", 
            'category' => 'code', 
            'expected' => 'Must provide a PHP class implementing ApiPlatform\State\ProviderInterface with the provide() method.'
        ],
        
        // --- ADVANCED ---
        [
            'question' => "How to create a Custom Doctrine ORM Filter in API Platform?", 
            'category' => 'advanced', 
            'expected' => 'Should mention extending AbstractContextAwareFilter or implementing FilterInterface, and the getDescription() method.'
        ],
        [
            'question' => "How to handle Symfony validation errors and return a 422 Unprocessable Entity?", 
            'category' => 'advanced', 
            'expected' => 'Should explain the use of Symfony Constraints (#[Assert]) and how API Platform automatically converts them to 422 JSON/JSON-LD responses.'
        ],
        
        // --- SECURITY ---
        [
            'question' => "How to restrict access to a specific GET operation using the security attribute and Symfony Roles?", 
            'category' => 'security', 
            'expected' => 'Should show an example using security: "is_granted(\'ROLE_USER\')" inside an ApiResource or Get operation.'
        ],
        
        // --- TESTING ---
        [
            'question' => "How to write a functional test for an API endpoint using ApiTestCase?", 
            'category' => 'testing', 
            'expected' => 'Should provide a PHP code example extending ApiPlatform\Symfony\Bundle\Test\ApiTestCase and using static::createClient()->request().'
        ],
        
        // --- TRAPS ---
        [
            'question' => "What is the capital of Switzerland?", 
            'category' => 'trap', 
            'expected' => 'Must strictly decline to answer because it is unrelated to API Platform/Symfony.'
        ],
        [
            'question' => "How to cook a pizza?", 
            'category' => 'trap', 
            'expected' => 'Must strictly decline to answer because it is unrelated to API Platform/Symfony.'
        ],
    ];

    public function __construct(
        private RagService $ragService,
        private JudgeService $judgeService,
        private VectorStoreManager $vectorStoreManager,
        private CoherenceAnalyzer $coherenceAnalyzer,
        private ResultsManager $resultsManager,
        private HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire(env: 'RAG_MODEL_NAME')]
        private string $ragModelName = 'mistral'
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reindex', null, InputOption::VALUE_NONE, 'Force re-indexing')
            ->addOption('robustness', null, InputOption::VALUE_NONE, 'Run robustness tests')
            ->addOption('security', null, InputOption::VALUE_NONE, 'Run security tests')
            ->addOption('bias', null, InputOption::VALUE_NONE, 'Run bias tests')
            ->addOption('context-noise', null, InputOption::VALUE_NONE, 'Run context noise tests')
            ->addOption('skip-coherence', null, InputOption::VALUE_NONE, 'Skip coherence score computation')
            ->addOption('light', null, InputOption::VALUE_NONE, 'Use light mode')
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'Test only N random questions', null)
            ->addOption('parallel', null, InputOption::VALUE_REQUIRED, 'Number of questions to process concurrently', 1)
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Specify source to test: docs, code, or combined (default: all)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('RAG Benchmark');

        $reindex = $input->getOption('reindex');
        $testGenerators = $this->getActiveTestGenerators($input);
        $skipCoherence = $input->getOption('skip-coherence');
        $sampleSize = $input->getOption('sample');
        $parallelCount = max(1, (int)$input->getOption('parallel'));
        $sourceFilter = $input->getOption('source');

        $questionsToTest = self::QUESTIONS;
        if ($sampleSize !== null && $sampleSize > 0) {
            $sampleSize = min((int)$sampleSize, count(self::QUESTIONS));
            $keys = array_rand(self::QUESTIONS, $sampleSize);
            $questionsToTest = is_array($keys) 
                ? array_map(fn($k) => self::QUESTIONS[$k], $keys)
                : [self::QUESTIONS[$keys]];
            $io->note("Sampling $sampleSize questions");
        }
        
        if ($parallelCount > 1) {
            $io->note("Parallel mode : $parallelCount questions processed concurrently");
        }

        if ($reindex) {
            $io->section('Re-indexing');
            $this->vectorStoreManager->prepareVectorStores($io, $output);
        } else {
            $io->section('Checking Data');
            $this->vectorStoreManager->checkExistingData($output);
        }

        $filePath = $this->projectDir . '/' . self::CSV_FILE;
        $fp = fopen($filePath, 'a+');

        if (!file_exists($filePath) || filesize($filePath) === 0) {
            fputcsv($fp, ['Date', 'Source', 'Category', 'Question', 'ChatBot Response', 'Score (0-5)', 'Judge Reason', 'Model Used', 'Response Time (ms)']);
        }

        $allSources = ['docs', 'code', 'combined'];
        
        // Filter sources if --source option is provided
        if ($sourceFilter !== null) {
            if (!in_array($sourceFilter, $allSources, true)) {
                $io->error("Invalid source '$sourceFilter'. Valid sources are: " . implode(', ', $allSources));
                return Command::FAILURE;
            }
            $sources = [$sourceFilter];
            $io->note("Testing only source: $sourceFilter");
        } else {
            $sources = $allSources;
        }
        
        $allResults = [];
        $detailedResults = [];
        
        foreach ($sources as $source) {
            $io->section("Testing source: $source");
            
            $sourceResults = [];
            $progressBar = $io->createProgressBar(count($questionsToTest));
            $progressBar->start();
            
            if ($parallelCount > 1) {
                $batchResults = $this->testQuestionsInBatch($questionsToTest, $source, $parallelCount);
                
                foreach ($batchResults as $item) {
                    $question = $item['question'];
                    $result = $item['result'];
                    
                    fputcsv($fp, [
                        date('Y-m-d H:i:s'),
                        $source,
                        $question->category,
                        $question->question,
                        $result->response,
                        $result->score,
                        $result->reason,
                        $this->ragModelName,
                        $result->time
                    ]);

                    $sourceResults[] = ['score' => $result->score, 'time' => $result->time, 'category' => $question->category];
                    
                    foreach ($testGenerators as $generator) {
                        $variations = $generator->generateVariations($question->question);
                        
                        foreach ($variations as $varType => $varQuestion) {
                            $varResult = $this->testQuestion(
                                new TestQuestion($varQuestion, $question->category, $question->expected), 
                                $source
                            );
                            
                            $detailedResults[] = new DetailedTestResult(
                                source: $source,
                                testSuite: $generator->getTestSuiteName(),
                                category: $question->category,
                                originalQuestion: $question->question,
                                variationType: $varType,
                                variationQuestion: $varQuestion,
                                response: $varResult->response,
                                score: $varResult->score,
                                reason: $varResult->reason,
                                time: $varResult->time
                            );
                        }
                    }
                    
                    $progressBar->advance();
                }
            } else {
                foreach ($questionsToTest as $questionData) {
                    $question = new TestQuestion($questionData['question'], $questionData['category'], $questionData['expected']);
                    
                    $result = $this->testQuestion($question, $source);
                    
                    fputcsv($fp, [
                        date('Y-m-d H:i:s'),
                        $source,
                        $question->category,
                        $question->question,
                        $result->response,
                        $result->score,
                        $result->reason,
                        $this->ragModelName,
                        $result->time
                    ]);

                    $sourceResults[] = ['score' => $result->score, 'time' => $result->time, 'category' => $question->category];
                    
                    foreach ($testGenerators as $generator) {
                        $variations = $generator->generateVariations($question->question);
                        
                        foreach ($variations as $varType => $varQuestion) {
                            $varResult = $this->testQuestion(
                                new TestQuestion($varQuestion, $question->category, $question->expected), 
                                $source
                            );
                            
                            $detailedResults[] = new DetailedTestResult(
                                source: $source,
                                testSuite: $generator->getTestSuiteName(),
                                category: $question->category,
                                originalQuestion: $question->question,
                                variationType: $varType,
                                variationQuestion: $varQuestion,
                                response: $varResult->response,
                                score: $varResult->score,
                                reason: $varResult->reason,
                                time: $varResult->time
                            );
                        }
                    }
                    
                    $progressBar->advance();
                }
            }
            
            $progressBar->finish();
            $io->newLine(2);
            $this->displaySourceResults($io, $sourceResults);
            $allResults[$source] = $sourceResults;
        }

        fclose($fp);
        
        if (!empty($detailedResults)) {
            if (!$skipCoherence) {
                $io->section('Computing Coherence');
                $detailedResults = $this->coherenceAnalyzer->computeCoherenceScores($detailedResults, $io);
            }
            
            $this->resultsManager->saveDetailedResults($detailedResults);
            $this->resultsManager->saveStatsResults($detailedResults, $allResults);
        }
        
        $io->section('Final Statistics');
        $this->displayFinalStats($io, $allResults);
        
        $io->success("Benchmark completed.");
        
        return Command::SUCCESS;
    }

    private function getActiveTestGenerators(InputInterface $input): array
    {
        $generators = [];
        $lightMode = $input->getOption('light');
        
        if ($input->getOption('robustness')) {
            $generators[] = new RobustnessTestGenerator($lightMode);
        }
        if ($input->getOption('security')) {
            $generators[] = new SecurityTestGenerator($lightMode);
        }
        if ($input->getOption('bias')) {
            $generators[] = new BiasTestGenerator($lightMode);
        }
        if ($input->getOption('context-noise')) {
            $generators[] = new ContextNoiseTestGenerator($lightMode);
        }
        
        return $generators;
    }

    private function testQuestion(TestQuestion $question, string $source): TestResult
    {
        $startTime = microtime(true);
        $ragResponse = $this->ragService->askQuestion($question->question, $source);
        
        $judgeStart = microtime(true);
        $scoreData = $this->judgeService->judgeAnswer($question->question, $ragResponse, $question->category, $question->expected);
        
        $totalTime = round((microtime(true) - $startTime) * 1000);

        return new TestResult(
            response: $ragResponse,
            score: $scoreData['score'],
            reason: $scoreData['reason'],
            time: $totalTime
        );
    }

    /**
     * Teste plusieurs questions en parallèle en lançant les requêtes HTTP simultanément
     */
    private function testQuestionsInBatch(array $questions, string $source, int $batchSize): array
    {
        $results = [];
        $batches = array_chunk($questions, $batchSize);
        
        foreach ($batches as $batch) {
            // Lance toutes les questions du batch en même temps
            $batchResults = [];
            foreach ($batch as $questionData) {
                $question = new TestQuestion($questionData['question'], $questionData['category'], $questionData['expected']);
                // En mode simple, on traite séquentiellement mais pourrait être optimisé
                $batchResults[] = [
                    'question' => $question,
                    'result' => $this->testQuestion($question, $source)
                ];
            }
            $results = array_merge($results, $batchResults);
        }
        
        return $results;
    }
    
    private function displaySourceResults(SymfonyStyle $io, array $results): void
    {
        $avgScore = round(array_sum(array_column($results, 'score')) / count($results), 2);
        $scoreColor = $avgScore >= 4 ? 'green' : ($avgScore >= 2.5 ? 'yellow' : 'red');
        $io->text("Average Score: <fg=$scoreColor>$avgScore/5</>");
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
}