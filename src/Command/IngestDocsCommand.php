<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption; // Add this line
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:ingest', description: 'Documentation indexing using chunks')]
class IngestDocsCommand extends Command
{
    private const EMBEDDING_MODEL = 'nomic-embed-text';
    private const CHUNK_SIZE = 1500;

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('folder', InputArgument::REQUIRED, 'Docs folder');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Clear the base before indexing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $folder = $input->getArgument('folder');

        $conn = $this->entityManager->getConnection();

        if ($input->getOption('clear')) {
            $conn->executeStatement("TRUNCATE TABLE vector_store");
            $output->writeln("<info>Base emptied</info>");
        }
        $folder = $input->getArgument('folder');
        
        if (!is_dir($folder)) {
            $output->writeln("<error>Folder not found</error>");
            return Command::FAILURE;
        }

        $conn = $this->entityManager->getConnection();
        $finder = new Finder();
        $finder->in($folder)->files()->name(['*.php', '*.md', '*.mdx']);

        $output->writeln("Indexing using: " . self::EMBEDDING_MODEL);

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement("TRUNCATE TABLE vector_store");

        $fileCount = $finder->count();
        $output->writeln("Fichiers trouvés : $fileCount");

        if ($fileCount === 0) {
            $output->writeln("<warning>No .md, .mdx or .php file found in folder</warning>");
            return Command::SUCCESS;
        }

        foreach ($finder as $file) {
            $fullContent = $file->getContents();
            $filename = $file->getRelativePathname();
            
            $chunks = $this->splitText($fullContent, self::CHUNK_SIZE);
            
            $output->write("Indexing $filename (" . count($chunks) . " chunks)... ");
            
            foreach ($chunks as $index => $chunk) {
                try {
                    $response = $this->httpClient->request('POST', 'http://127.0.0.1:11434/api/embed', [
                        'json' => [
                            'model' => self::EMBEDDING_MODEL,
                            'input' => $chunk,
                        ],
                    ]);

                    $data = $response->toArray();
                    $embeddings = $data['embeddings'][0] ?? null;

                    if (!$embeddings) {
                        continue; 
                    }

                    $vectorStr = '[' . implode(',', $embeddings) . ']';
                    
                    $sql = "INSERT INTO vector_store (content, metadata, vector) VALUES (:content, :metadata, :vector)";
                    $conn->executeStatement($sql, [
                        'content' => $chunk,
                        'metadata' => json_encode([
                            'filename' => $filename,
                            'chunk_index' => $index
                        ]),
                        'vector' => $vectorStr
                    ]);

                } catch (\Exception $e) {
                    $output->writeln("\n<error>Error on chunk $index from $filename : " . $e->getMessage() . "</error>");
                }
            }
            $output->writeln("<info>OK</info>");
        }

        return Command::SUCCESS;
    }

    private function splitText(string $text, int $maxSize): array
    {
        $chunks = [];
        $paragraphs = explode("\n\n", $text);
        $currentChunk = "";

        foreach ($paragraphs as $paragraph) {
            if (strlen($currentChunk) + strlen($paragraph) > $maxSize) {
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                }
                $currentChunk = $paragraph;
            } else {
                $currentChunk .= "\n\n" . $paragraph;
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }
}