<?php

namespace App\Service\Rag;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

class VectorStoreManager
{
    private const FIXTURES_FILE = 'files_to_index.txt';

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir
    ) {
    }

    public function prepareVectorStores(SymfonyStyle $io, OutputInterface $output): void
    {
        $conn = $this->entityManager->getConnection();

        $io->text("Clearing vector stores...");
        $conn->executeStatement("TRUNCATE TABLE vector_store_docs");
        $conn->executeStatement("TRUNCATE TABLE vector_store_code");

        $pathsToIndex = [
            'docs/',
            'core/tests/Functional/',
            '.agents/skills/'
        ];

        $fixturesPath = $this->projectDir . '/' . self::FIXTURES_FILE;
        if (file_exists($fixturesPath)) {
            $fixtures = file($fixturesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $pathsToIndex = array_merge($pathsToIndex, $fixtures);
            $io->note(sprintf("Loaded %d extra paths from %s", count($fixtures), self::FIXTURES_FILE));
        }

        $pathsToIndex = array_unique($pathsToIndex);
        $io->text(sprintf("Indexing %d paths...", count($pathsToIndex)));

        $progressBar = new ProgressBar($output, count($pathsToIndex));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->start();

        foreach ($pathsToIndex as $path) {
            $fullPath = (str_starts_with($path, '/') || str_contains($path, ':'))
                ? $path
                : $this->projectDir . '/' . $path;

            if (!file_exists($fullPath)) {
                $progressBar->advance();
                continue;
            }

            $progressBar->setMessage("Indexing " . basename($path));

            $process = new Process([
                'php', 'bin/console', 'app:ingest', $fullPath, '--force',
            ]);

            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                $io->error("Error indexing $path: " . $process->getErrorOutput());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        $this->checkExistingData($output);
    }

    public function checkExistingData(OutputInterface $output): void
    {
        $conn = $this->entityManager->getConnection();
        $tableData = [];
        $allReady = true;

        $docsCount = $conn->fetchOne("SELECT COUNT(*) FROM vector_store_docs");
        $codeCount = $conn->fetchOne("SELECT COUNT(*) FROM vector_store_code");

        $tableData[] = ['vector_store_docs', $docsCount, $docsCount > 0 ? 'OK' : 'EMPTY'];
        $tableData[] = ['vector_store_code', $codeCount, $codeCount > 0 ? 'OK' : 'EMPTY'];

        $combinedCount = $docsCount + $codeCount;
        $tableData[] = ['(virtual) combined', $combinedCount, $combinedCount > 0 ? 'OK' : 'EMPTY'];

        if ($docsCount == 0 && $codeCount == 0) {
            $allReady = false;
        }

        (new Table($output))
            ->setHeaders(['Table', 'Chunks', 'Status'])
            ->setRows($tableData)
            ->render();

        if (!$allReady) {
            throw new \RuntimeException("Vector stores are empty. Run with --reindex first.");
        }
    }
}
