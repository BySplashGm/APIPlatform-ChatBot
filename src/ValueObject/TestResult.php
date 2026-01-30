<?php

namespace App\ValueObject;

readonly class TestResult
{
    public function __construct(
        public string $response,
        public int $score,
        public string $reason,
        public int $time
    ) {
    }
}
