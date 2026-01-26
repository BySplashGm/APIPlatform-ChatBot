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
        $this->addArgument('folder', InputArgument::REQUIRED, 'Root folder to scan');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Clear database before indexing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $folder = $input->getArgument('folder');
        $conn = $this->entityManager->getConnection();

        if ($input->getOption('clear')) {
            $conn->executeStatement("TRUNCATE TABLE vector_store");
            $output->writeln("<info>Database cleared.</info>");
        }

        if (!is_dir($folder)) {
            $output->writeln("<error>Folder not found: $folder</error>");
            return Command::FAILURE;
        }

        $finder = new Finder();
        $finder->in($folder)
            ->files()
            ->name(['*.php', '*.md', '*.mdx'])
            ->notPath(['vendor', 'var', 'cache', '.git']);

        $fileCount = $finder->count();
        $output->writeln("Analyzing $fileCount files...");

        foreach ($finder as $file) {
            $content = $file->getContents();
            $filename = $file->getRelativePathname();
            $extension = $file->getExtension();
            $chunks = [];

            if ($extension === 'php') {
                $chunks = $this->chunkPhpCode($content, $filename);
                $type = 'CODE';
            } elseif (in_array($extension, ['md', 'mdx'])) {
                $chunks = $this->chunkMarkdownSmart($content, $filename);
                $type = 'DOC';
            }

            if (empty($chunks)) continue;

            $output->write("[$type] $filename (" . count($chunks) . " chunks)... ");

            foreach ($chunks as $index => $chunkText) {
                try {
                    $response = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
                        'json' => [
                            'model' => self::EMBEDDING_MODEL,
                            'input' => $chunkText,
                        ],
                    ]);

                    $embeddings = $response->toArray()['embeddings'][0] ?? null;

                    if ($embeddings) {
                        $vectorStr = '[' . implode(',', $embeddings) . ']';
                        $conn->executeStatement(
                            "INSERT INTO vector_store (content, metadata, vector) VALUES (:content, :metadata, :vector)",
                            [
                                'content' => $chunkText,
                                'metadata' => json_encode([
                                    'filename' => $filename,
                                    'chunk_index' => $index,
                                    'type' => $type
                                ]),
                                'vector' => $vectorStr
                            ]
                        );
                    }
                } catch (\Exception $e) {
                    $output->writeln("<error>Failed</error>");
                    continue;
                }
            }
            $output->writeln("<info>OK</info>");
        }

        return Command::SUCCESS;
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