<?php

namespace App\Command;

use App\Service\Benchmark\CoherenceAnalyzer;
use App\Service\Benchmark\JudgeService;
use App\Service\Benchmark\RagService;
use App\Service\Benchmark\ResultsManager;
use App\Service\Benchmark\TestGenerator\BiasTestGenerator;
use App\Service\Benchmark\TestGenerator\ContextNoiseTestGenerator;
use App\Service\Benchmark\TestGenerator\RobustnessTestGenerator;
use App\Service\Benchmark\TestGenerator\SecurityTestGenerator;
use App\Service\Benchmark\TestGenerator\TestGeneratorInterface;
use App\Service\Benchmark\VectorStoreManager;
use App\ValueObject\DetailedTestResult;
use App\ValueObject\TestQuestion;
use App\ValueObject\TestResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;

#[AsCommand(name: 'app:benchmark', description: 'Run quality tests with different documentation sources')]
class BenchmarkCommand extends Command
{
    private const CSV_FILE = 'benchmark_results.csv';

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
        private RagService $ragService,
        private JudgeService $judgeService,
        private VectorStoreManager $vectorStoreManager,
        private CoherenceAnalyzer $coherenceAnalyzer,
        private ResultsManager $resultsManager,
        private string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reindex', null, InputOption::VALUE_NONE, 'Force re-indexing (clear and rebuild all data)')
            ->addOption('robustness', null, InputOption::VALUE_NONE, 'Run robustness tests (variations: lowercase, uppercase, typos, punctuation)')
            ->addOption('security', null, InputOption::VALUE_NONE, 'Run security tests (prompt injection, jailbreak attempts)')
            ->addOption('bias', null, InputOption::VALUE_NONE, 'Run bias tests (gender, cultural variations)')
            ->addOption('context-noise', null, InputOption::VALUE_NONE, 'Run context noise tests (distracting text before/after question)')
            ->addOption('skip-coherence', null, InputOption::VALUE_NONE, 'Skip coherence score computation (faster execution)')
            ->addOption('light', null, InputOption::VALUE_NONE, 'Use light mode (fewer variations per test suite, faster)')
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'Test only N random questions instead of all', null);
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
        $testGenerators = $this->getActiveTestGenerators($input);
        $skipCoherence = $input->getOption('skip-coherence');
        $sampleSize = $input->getOption('sample');

        // Select questions to test
        $questionsToTest = self::QUESTIONS;
        if ($sampleSize !== null && $sampleSize > 0) {
            $sampleSize = min((int)$sampleSize, count(self::QUESTIONS));
            $keys = array_rand(self::QUESTIONS, $sampleSize);
            $questionsToTest = is_array($keys) 
                ? array_map(fn($k) => self::QUESTIONS[$k], $keys)
                : [self::QUESTIONS[$keys]];
            $io->note("Sampling $sampleSize questions out of " . count(self::QUESTIONS));
        }

        if ($reindex) {
            $io->section('Step 1/4: Re-indexing Vector Stores');
            $this->vectorStoreManager->prepareVectorStores($io, $output);
        } else {
            $io->section('Step 1/4: Checking Existing Data');
            $this->vectorStoreManager->checkExistingData($output);
        }

        $filePath = $this->projectDir . '/' . self::CSV_FILE;
        $fp = fopen($filePath, 'a+');

        if (!file_exists($filePath) || filesize($filePath) === 0) {
            fputcsv($fp, ['Date', 'Source', 'Category', 'Question', 'ChatBot Response', 'Score (0-5)', 'Judge Reason', 'Model Used', 'Response Time (ms)']);
        }

        $sources = ['docs', 'code', 'combined'];
        $allResults = [];
        $detailedResults = [];
        $currentStep = 2;
        
        foreach ($sources as $source) {
            $io->section("Step $currentStep/4: Testing with '$source'");
            $currentStep++;
            
            $sourceResults = [];
            $progressBar = $io->createProgressBar(count($questionsToTest));
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %message%');
            $progressBar->start();
            
            foreach ($questionsToTest as $questionData) {
                $question = new TestQuestion($questionData['question'], $questionData['category'], $questionData['expected']);
                
                $shortQuestion = strlen($question->question) > 50 
                    ? substr($question->question, 0, 47) . '...' 
                    : $question->question;
                    
                $progressBar->setMessage("[{$question->category}] $shortQuestion");
                
                $io->writeln("\n<comment>Testing original question: {$question->question}</comment>", OutputInterface::VERBOSITY_VERBOSE);
                
                // Test original question
                $io->writeln("  -> Calling RAG...", OutputInterface::VERBOSITY_VERBOSE);
                $result = $this->testQuestion($question, $source);
                $io->writeln("  -> Got response (score: {$result->score})", OutputInterface::VERBOSITY_VERBOSE);
                
                fputcsv($fp, [
                    date('Y-m-d H:i:s'),
                    $source,
                    $question->category,
                    $question->question,
                    $result->response,
                    $result->score,
                    $result->reason,
                    'mistral',
                    $result->time
                ]);

                $sourceResults[] = ['score' => $result->score, 'time' => $result->time, 'category' => $question->category];
                
                // Advanced tests if enabled
                foreach ($testGenerators as $generator) {
                    $io->writeln("  -> Running {$generator->getTestSuiteName()} tests...", OutputInterface::VERBOSITY_VERBOSE);
                    $variations = $generator->generateVariations($question->question);
                    $io->writeln("     Generated " . count($variations) . " variations", OutputInterface::VERBOSITY_VERBOSE);
                    
                    foreach ($variations as $varType => $varQuestion) {
                        $io->writeln("     Testing variation: $varType", OutputInterface::VERBOSITY_VERY_VERBOSE);
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
            
            $progressBar->finish();
            $io->newLine(2);
            $this->displaySourceResults($io, $sourceResults);
            $allResults[$source] = $sourceResults;
        }

        fclose($fp);
        
        // Save detailed advanced test results and compute coherence scores
        if (!empty($detailedResults)) {
            if (!$skipCoherence) {
                $io->section('Computing Coherence Scores');
                $detailedResults = $this->coherenceAnalyzer->computeCoherenceScores($detailedResults, $io);
            } else {
                $io->note('Coherence computation skipped (--skip-coherence)');
            }
            
            $this->resultsManager->saveDetailedResults($detailedResults);
            $this->resultsManager->saveStatsResults($detailedResults, $allResults);
        }
        
        $io->section('Final Statistics');
        $this->displayFinalStats($io, $allResults);
        
        $io->newLine();
        $io->success("Benchmark completed!");
        $filesToShow = ["Results saved in: <fg=cyan>$filePath</>"];
        
        if (!empty($detailedResults)) {
            $filesToShow[] = "Detailed results: <fg=cyan>" . $this->resultsManager->getDetailedFilePath() . "</>";
            $filesToShow[] = "Statistics: <fg=cyan>" . $this->resultsManager->getStatsFilePath() . "</>";
        }
        
        $filesToShow[] = "Total tests: <fg=yellow>" . (count($sources) * count($questionsToTest)) . "</>";
        
        $io->text($filesToShow);
        
        return Command::SUCCESS;
    }

    /**
     * @return TestGeneratorInterface[]
     */
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
        $ragTime = round((microtime(true) - $startTime) * 1000);
        
        $judgeStart = microtime(true);
        $scoreData = $this->judgeService->judgeAnswer($question->question, $ragResponse, $question->category, $question->expected);
        $judgeTime = round((microtime(true) - $judgeStart) * 1000);
        
        $totalTime = round((microtime(true) - $startTime) * 1000);

        return new TestResult(
            response: $ragResponse,
            score: $scoreData['score'],
            reason: $scoreData['reason'],
            time: $totalTime
        );
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
}