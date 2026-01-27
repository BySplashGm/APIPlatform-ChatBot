<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:ingest', description: 'Smart Chunking indexing for Markdown and PHP')]
class IngestDocsCommand extends Command
{
    private const EMBEDDING_MODEL = 'nomic-embed-text';
    private const CHUNK_SIZE = 1500;
    private const CODE_OVERLAP = 200; 

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'File or Folder to scan')
            ->addOption('target', 't', InputOption::VALUE_OPTIONAL, 'Target table (docs, code, combined)', 'combined')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear database before indexing')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be indexed without actually doing it')
            ->addOption('exclude', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Paths to exclude', [])
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show statistics after indexing')
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Number of chunks to process before showing progress', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        $conn = $this->entityManager->getConnection();
        $dryRun = $input->getOption('dry-run');
        $showStats = $input->getOption('stats');
        $batchSize = (int)($input->getOption('batch-size') ?? 10);
        $target = $input->getOption('target');
        
        $tableName = match($target) {
            'docs' => 'vector_store_docs',
            'code' => 'vector_store_code',
            'combined' => 'vector_store_combined',
            default => throw new \InvalidArgumentException("Invalid target: $target")
        };

        $excludePaths = array_merge(
            ['vendor', 'var', 'cache', '.git'],
            $input->getOption('exclude')
        );

        if ($dryRun) {
            $output->writeln("<comment>DRY RUN MODE - No actual indexing will occur</comment>");
        }

        $output->writeln("<info>Target table: $tableName</info>");

        if ($input->getOption('clear') && !$dryRun) {
            $conn->executeStatement("TRUNCATE TABLE $tableName");
            $output->writeln("<info>Database cleared.</info>");
        }

        if (!file_exists($path)) {
            $output->writeln("<error>Path not found: $path</error>");
            return Command::FAILURE;
        }

        $filesToProcess = [];
        
        if (is_file($path)) {
            $filesToProcess = [new \SplFileInfo($path)];
            $fileCount = 1;
            $output->writeln("Indexing single file: $path");
        } else {
            $finder = new Finder();
            $finder->in($path)
                ->files()
                ->name(['*.php', '*.md', '*.mdx'])
                ->notPath($excludePaths);
            
            $filesToProcess = $finder;
            $fileCount = $finder->count();
            $output->writeln("Analyzing directory ($fileCount files)...");
        }

        $stats = [
            'files_processed' => 0,
            'chunks_created' => 0,
            'chunks_indexed' => 0,
            'errors' => 0,
            'by_type' => ['CODE' => 0, 'DOC' => 0],
            'total_size' => 0,
            'start_time' => microtime(true)
        ];

        $chunkBuffer = 0;

        foreach ($filesToProcess as $file) {
            $content = file_get_contents($file->getRealPath());
            $filename = ($file instanceof \Symfony\Component\Finder\SplFileInfo) 
                ? $file->getRelativePathname() 
                : $file->getBasename(); 
                
            $extension = $file->getExtension();
            $chunks = [];

            $stats['total_size'] += strlen($content);

            if ($extension === 'php') {
                $chunks = $this->chunkPhpCode($content, $filename);
                $type = 'CODE';
            } elseif (in_array($extension, ['md', 'mdx'])) {
                $chunks = $this->chunkMarkdownSmart($content, $filename);
                $type = 'DOC';
            }

            if (empty($chunks)) continue;

            $stats['files_processed']++;
            $stats['chunks_created'] += count($chunks);
            $stats['by_type'][$type] += count($chunks);

            $output->write("[$type] $filename (" . count($chunks) . " chunks)... ");

            if ($dryRun) {
                $output->writeln("<comment>SKIPPED (dry-run)</comment>");
                continue;
            }

            foreach ($chunks as $index => $chunkText) {
                try {
                    $response = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
                        'json' => ['model' => self::EMBEDDING_MODEL, 'input' => $chunkText],
                        'timeout' => 300
                    ]);

                    $embeddings = $response->toArray()['embeddings'][0] ?? null;

                    if ($embeddings) {
                        $vectorStr = '[' . implode(',', $embeddings) . ']';
                        $conn->executeStatement(
                            "INSERT INTO $tableName (content, metadata, vector) VALUES (:content, :metadata, :vector)",
                            [
                                'content' => $chunkText,
                                'metadata' => json_encode([
                                    'filename' => $filename,
                                    'chunk_index' => $index,
                                    'type' => $type,
                                    'file_size' => strlen($content),
                                    'indexed_at' => date('Y-m-d H:i:s')
                                ]),
                                'vector' => $vectorStr
                            ]
                        );
                        $stats['chunks_indexed']++;
                        $chunkBuffer++;

                        if ($chunkBuffer >= $batchSize) {
                            $output->write(".");
                            $chunkBuffer = 0;
                        }
                    }
                } catch (\Exception $e) {
                    $output->writeln("<error>Failed: {$e->getMessage()}</error>");
                    $stats['errors']++;
                    continue;
                }
            }
            $output->writeln("<info>OK</info>");
        }

        $stats['duration'] = round(microtime(true) - $stats['start_time'], 2);

        if ($showStats || $dryRun) {
            $this->displayIndexingStats($output, $stats);
        }

        return Command::SUCCESS;
    }

    private function displayIndexingStats(OutputInterface $output, array $stats): void
    {
        $output->writeln("\n<info>=== Indexing Statistics ===</info>");
        $output->writeln("Files processed: {$stats['files_processed']}");
        $output->writeln("Total chunks created: {$stats['chunks_created']}");
        $output->writeln("Chunks indexed: {$stats['chunks_indexed']}");
        $output->writeln("Errors: {$stats['errors']}");
        $output->writeln("Code chunks: {$stats['by_type']['CODE']}");
        $output->writeln("Doc chunks: {$stats['by_type']['DOC']}");
        $output->writeln("Total content size: " . $this->formatBytes($stats['total_size']));
        $output->writeln("Duration: {$stats['duration']}s");
        
        if ($stats['duration'] > 0) {
            $chunksPerSecond = round($stats['chunks_indexed'] / $stats['duration'], 2);
            $output->writeln("Speed: $chunksPerSecond chunks/second");
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function chunkMarkdownSmart(string $text, string $filename): array
    {
        $chunks = [];
        $parts = preg_split('/^(#+ .*)$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $currentHeader = "Introduction";
        
        for ($i = 0; $i < count($parts); $i++) {
            if (preg_match('/^#+ /', $parts[$i])) {
                $currentHeader = trim($parts[$i]);
                continue;
            }

            $content = $parts[$i];
            
            if (strlen($content) < self::CHUNK_SIZE) {
                $chunks[] = "File: $filename\nSection: $currentHeader\n\n" . trim($content);
            } else {
                $subChunks = $this->splitByParagraphs($content, self::CHUNK_SIZE);
                foreach ($subChunks as $subChunk) {
                    $chunks[] = "File: $filename\nSection: $currentHeader (Continued)\n\n" . trim($subChunk);
                }
            }
        }

        return $chunks;
    }

    private function chunkPhpCode(string $text, string $filename): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $chunks = [];
        $length = strlen($text);
        $offset = 0;

        while ($offset < $length) {
            $chunk = substr($text, $offset, self::CHUNK_SIZE);
            
            if ($offset + self::CHUNK_SIZE < $length) {
                $lastNewline = strrpos($chunk, "\n");
                if ($lastNewline !== false && $lastNewline > self::CHUNK_SIZE * 0.7) {
                    $chunk = substr($chunk, 0, $lastNewline);
                }
            }

            $chunks[] = "File: $filename (PHP Code)\n\n" . $chunk;

            $step = strlen($chunk) - self::CODE_OVERLAP;
            if ($step <= 0) $step = strlen($chunk);
            
            $offset += $step;
        }

        return $chunks;
    }

    private function splitByParagraphs(string $text, int $maxSize): array
    {
        $chunks = [];
        $paragraphs = explode("\n\n", $text);
        $currentChunk = "";

        foreach ($paragraphs as $paragraph) {
            if (strlen($currentChunk) + strlen($paragraph) > $maxSize) {
                if (!empty($currentChunk)) {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = $paragraph;
            } else {
                $currentChunk .= "\n\n" . $paragraph;
            }
        }
        
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }
        
        return $chunks;
    }
}