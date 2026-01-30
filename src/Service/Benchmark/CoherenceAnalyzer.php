<?php

namespace App\Service\Benchmark;

use App\ValueObject\DetailedTestResult;
use Symfony\Component\Console\Style\SymfonyStyle;

class CoherenceAnalyzer
{
    public function __construct(
        private RagService $ragService
    ) {
    }

    /**
     * @param DetailedTestResult[] $detailedResults
     * @return DetailedTestResult[]
     */
    public function computeCoherenceScores(array $detailedResults, SymfonyStyle $io): array
    {
        // Group results by original question
        $grouped = [];
        foreach ($detailedResults as $idx => $result) {
            $key = $result->source . '|||' . $result->originalQuestion;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = ['idx' => $idx, 'response' => $result->response];
        }
        
        $progressBar = $io->createProgressBar(count($grouped));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% Computing embeddings...');
        $progressBar->start();
        
        $updatedResults = [];
        
        foreach ($grouped as $responses) {
            if (count($responses) < 2) {
                $progressBar->advance();
                continue;
            }
            
            // Get embeddings for all responses
            $embeddings = [];
            foreach ($responses as $item) {
                try {
                    $embeddings[$item['idx']] = $this->ragService->getEmbeddingVector($item['response']);
                } catch (\Exception $e) {
                    $embeddings[$item['idx']] = null;
                }
            }
            
            // Compute cosine similarity with first response (original)
            $firstIdx = $responses[0]['idx'];
            $firstEmb = $embeddings[$firstIdx] ?? null;
            
            if ($firstEmb) {
                foreach ($responses as $item) {
                    if ($item['idx'] === $firstIdx) {
                        continue;
                    }
                    
                    $currentEmb = $embeddings[$item['idx']] ?? null;
                    if ($currentEmb) {
                        $similarity = $this->cosineSimilarity($firstEmb, $currentEmb);
                        $oldResult = $detailedResults[$item['idx']];
                        
                        $updatedResults[$item['idx']] = new DetailedTestResult(
                            source: $oldResult->source,
                            testSuite: $oldResult->testSuite,
                            category: $oldResult->category,
                            originalQuestion: $oldResult->originalQuestion,
                            variationType: $oldResult->variationType,
                            variationQuestion: $oldResult->variationQuestion,
                            response: $oldResult->response,
                            score: $oldResult->score,
                            reason: $oldResult->reason,
                            time: $oldResult->time,
                            coherenceScore: round($similarity, 3)
                        );
                    }
                }
            }
            
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $io->newLine(2);
        
        // Merge updated results with original
        foreach ($updatedResults as $idx => $result) {
            $detailedResults[$idx] = $result;
        }
        
        return $detailedResults;
    }

    private function cosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;
        
        for ($i = 0; $i < count($vec1); $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }
        
        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);
        
        if ($norm1 == 0 || $norm2 == 0) {
            return 0.0;
        }
        
        return $dotProduct / ($norm1 * $norm2);
    }
}
