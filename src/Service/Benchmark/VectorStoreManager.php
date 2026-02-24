<?php

namespace App\Service\Benchmark;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class VectorStoreManager
{
    private const FIXTURES_FILE = 'files_to_index.txt';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $projectDir
    ) {
    }

    public function prepareVectorStores(SymfonyStyle $io, OutputInterface $output): void
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

    public function checkExistingData(OutputInterface $output): void
    {
        $conn = $this->entityManager->getConnection();
        $tableData = [];
        $allReady = true;
        
        foreach (['docs', 'code', 'combined'] as $table) {
            $count = $conn->fetchOne("SELECT COUNT(*) FROM vector_store_$table");
            $tableData[] = ["vector_store_$table", $count, $count > 0 ? 'OK' : 'EMPTY'];
            if ($count == 0) {
                $allReady = false;
            }
        }
        
        $table = new \Symfony\Component\Console\Helper\Table($output);
        $table->setHeaders(['Table', 'Chunks', 'Status'])->setRows($tableData)->render();
        
        if (!$allReady) {
            throw new \RuntimeException("Some tables are empty. Run with --reindex first.");
        }
    }
}
