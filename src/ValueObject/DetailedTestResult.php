<?php

namespace App\ValueObject;

readonly class DetailedTestResult
{
    public function __construct(
        public string $source,
        public string $testSuite,
        public string $category,
        public string $originalQuestion,
        public string $variationType,
        public string $variationQuestion,
        public string $response,
        public int $score,
        public string $reason,
        public int $time,
        public ?float $coherenceScore = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'test_suite' => $this->testSuite,
            'category' => $this->category,
            'original_question' => $this->originalQuestion,
            'variation_type' => $this->variationType,
            'variation_question' => $this->variationQuestion,
            'response' => $this->response,
            'score' => $this->score,
            'reason' => $this->reason,
            'time' => $this->time,
            'coherence_score' => $this->coherenceScore
        ];
    }
}
