<?php

namespace App\Service\Benchmark;

use App\ValueObject\DetailedTestResult;

class ResultsManager
{
    private const CSV_DETAILED_FILE = 'benchmark_results_detailed.csv';
    private const CSV_STATS_FILE = 'benchmark_results_stats.csv';

    public function __construct(
        private string $projectDir
    ) {
    }

    /**
     * @param DetailedTestResult[] $detailedResults
     */
    public function saveDetailedResults(array $detailedResults): void
    {
        $filePath = $this->projectDir . '/' . self::CSV_DETAILED_FILE;
        $fp = fopen($filePath, 'w');
        
        fputcsv($fp, ['Date', 'Source', 'Test Suite', 'Category', 'Original Question', 'Variation Type', 'Variation Question', 'Response', 'Score', 'Reason', 'Time (ms)', 'Coherence Score']);
        
        foreach ($detailedResults as $result) {
            fputcsv($fp, [
                date('Y-m-d H:i:s'),
                $result->source,
                $result->testSuite,
                $result->category,
                $result->originalQuestion,
                $result->variationType,
                $result->variationQuestion,
                $result->response,
                $result->score,
                $result->reason,
                $result->time,
                $result->coherenceScore ?? ''
            ]);
        }
        
        fclose($fp);
    }

    public function saveStatsResults(array $detailedResults, array $allResults): void
    {
        $filePath = $this->projectDir . '/' . self::CSV_STATS_FILE;
        $fp = fopen($filePath, 'w');
        
        fputcsv($fp, ['Metric', 'Source', 'Category/Type', 'Value']);
        
        // Stats by source and category
        foreach ($allResults as $source => $results) {
            $byCategory = [];
            foreach ($results as $r) {
                if (!isset($byCategory[$r['category']])) {
                    $byCategory[$r['category']] = ['scores' => [], 'times' => []];
                }
                $byCategory[$r['category']]['scores'][] = $r['score'];
                $byCategory[$r['category']]['times'][] = $r['time'];
            }
            
            foreach ($byCategory as $cat => $data) {
                $avgScore = round(array_sum($data['scores']) / count($data['scores']), 2);
                $avgTime = round(array_sum($data['times']) / count($data['times']), 0);
                fputcsv($fp, ['avg_score_by_category', $source, $cat, $avgScore]);
                fputcsv($fp, ['avg_time_by_category', $source, $cat, $avgTime]);
            }
        }
        
        // Stats by test suite and variation type
        $byTestSuite = [];
        foreach ($detailedResults as $result) {
            $key = $result->source . '_' . $result->testSuite . '_' . $result->variationType;
            if (!isset($byTestSuite[$key])) {
                $byTestSuite[$key] = [
                    'source' => $result->source, 
                    'suite' => $result->testSuite,
                    'type' => $result->variationType, 
                    'scores' => [], 
                    'times' => [],
                    'coherence_scores' => []
                ];
            }
            $byTestSuite[$key]['scores'][] = $result->score;
            $byTestSuite[$key]['times'][] = $result->time;
            if ($result->coherenceScore !== null) {
                $byTestSuite[$key]['coherence_scores'][] = $result->coherenceScore;
            }
        }
        
        foreach ($byTestSuite as $data) {
            $avgScore = round(array_sum($data['scores']) / count($data['scores']), 2);
            $avgTime = round(array_sum($data['times']) / count($data['times']), 0);
            $passRate = round(count(array_filter($data['scores'], fn($s) => $s >= 3)) / count($data['scores']) * 100, 1);
            
            $label = $data['suite'] . '/' . $data['type'];
            
            fputcsv($fp, ['avg_score_by_test', $data['source'], $label, $avgScore]);
            fputcsv($fp, ['avg_time_by_test', $data['source'], $label, $avgTime]);
            fputcsv($fp, ['pass_rate_by_test', $data['source'], $label, $passRate . '%']);
            
            if (!empty($data['coherence_scores'])) {
                $avgCoherence = round(array_sum($data['coherence_scores']) / count($data['coherence_scores']), 3);
                fputcsv($fp, ['avg_coherence_by_test', $data['source'], $label, $avgCoherence]);
            }
        }
        
        // Global coherence stats by source
        $coherenceBySource = [];
        foreach ($detailedResults as $result) {
            if ($result->coherenceScore !== null) {
                if (!isset($coherenceBySource[$result->source])) {
                    $coherenceBySource[$result->source] = [];
                }
                $coherenceBySource[$result->source][] = $result->coherenceScore;
            }
        }
        
        foreach ($coherenceBySource as $source => $scores) {
            $avgCoherence = round(array_sum($scores) / count($scores), 3);
            $minCoherence = round(min($scores), 3);
            $maxCoherence = round(max($scores), 3);
            
            fputcsv($fp, ['coherence_avg', $source, 'all', $avgCoherence]);
            fputcsv($fp, ['coherence_min', $source, 'all', $minCoherence]);
            fputcsv($fp, ['coherence_max', $source, 'all', $maxCoherence]);
        }
        
        fclose($fp);
    }

    public function getDetailedFilePath(): string
    {
        return $this->projectDir . '/' . self::CSV_DETAILED_FILE;
    }

    public function getStatsFilePath(): string
    {
        return $this->projectDir . '/' . self::CSV_STATS_FILE;
    }
}
