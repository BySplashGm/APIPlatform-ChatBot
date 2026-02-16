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

#[AsCommand(name: 'app:ingest', description: 'Smart Auto-Routing Ingestion')]
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
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear ALL tables before indexing')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        $conn = $this->entityManager->getConnection();

        if ($input->getOption('clear')) {
            $conn->executeStatement("TRUNCATE TABLE vector_store_docs");
            $conn->executeStatement("TRUNCATE TABLE vector_store_code");
            $output->writeln("Tables cleared.");
        }

        if (!file_exists($path)) {
            $output->writeln("Path not found.");
            return Command::FAILURE;
        }

        $finder = new Finder();
        if (is_file($path)) {
            $finder->append([$path]);
        } else {
            $finder->in($path)->files()->name(['*.php', '*.md', '*.mdx'])->notPath(['vendor', 'var', 'node_modules', '.git']);
        }
        
        // On n'affiche le message que s'il y a beaucoup de fichiers
        if (is_dir($path)) {
            $output->writeln("Found " . $finder->count() . " files. Starting ingestion...");
        }

        $conn->beginTransaction();

        try {
            foreach ($finder as $file) {
                $content = file_get_contents($file->getRealPath());
                
                // CORRECTION ICI : Gestion du nom de fichier selon le type d'objet
                if ($file instanceof \Symfony\Component\Finder\SplFileInfo) {
                    $filename = $file->getRelativePathname();
                } else {
                    $filename = $file->getFilename();
                }

                $extension = $file->getExtension();

                if ($extension === 'php') {
                    $table = 'vector_store_code';
                    $chunks = $this->chunkPhpCode($content, $filename);
                    $type = 'CODE';
                } else {
                    $table = 'vector_store_docs';
                    $chunks = $this->chunkMarkdownHierarchy($content, $filename);
                    $type = 'DOC';
                }

                $output->write("Processing $filename ($type)... ");

                foreach ($chunks as $i => $chunkText) {
                    $vector = $this->getEmbedding($chunkText);
                    
                    if (!$vector) {
                        continue;
                    }

                    $conn->executeStatement(
                        "INSERT INTO $table (content, metadata, vector) VALUES (:content, :metadata, :vector)",
                        [
                            'content' => $chunkText,
                            'metadata' => json_encode([
                                'filename' => $filename,
                                'index' => $i,
                                'type' => $type,
                                'size' => strlen($chunkText)
                            ]),
                            'vector' => $vector
                        ]
                    );
                }
                $output->writeln("OK");
            }

            $conn->commit();
            // $output->writeln("Ingestion Complete.");

        } catch (\Exception $e) {
            $conn->rollBack();
            $output->writeln("Error: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getEmbedding(string $text): ?string
    {
        try {
            $response = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
                'json' => ['model' => self::EMBEDDING_MODEL, 'input' => $text],
                'timeout' => 10
            ]);
            $raw = $response->toArray()['embeddings'][0] ?? null;
            return $raw ? '[' . implode(',', $raw) . ']' : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function chunkMarkdownHierarchy(string $text, string $filename): array
    {
        $chunks = [];
        $parts = preg_split('/^(#+ .*)$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        $currentContext = "General";
        
        foreach ($parts as $part) {
            if (preg_match('/^#+ (.*)/', $part, $matches)) {
                $currentContext = trim($matches[1]);
                continue;
            }
            
            if (trim($part) === '') continue;

            $enrichedContent = "Source: $filename\nContext: $currentContext\n---\n" . trim($part);
            
            if (strlen($enrichedContent) > self::CHUNK_SIZE) {
                $subChunks = $this->splitByParagraphs($part, self::CHUNK_SIZE - 200);
                foreach ($subChunks as $sub) {
                    $chunks[] = "Source: $filename\nContext: $currentContext (Cont.)\n---\n" . $sub;
                }
            } else {
                $chunks[] = $enrichedContent;
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
            $chunks[] = "File: $filename (PHP)\n```php\n" . $chunk . "\n```"; 
            
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