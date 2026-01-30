<?php

namespace App\ValueObject;

readonly class TestQuestion
{
    public function __construct(
        public string $question,
        public string $category,
        public string $expected
    ) {
    }
}
