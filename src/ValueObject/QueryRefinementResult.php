<?php

namespace App\ValueObject;

readonly class QueryRefinementResult
{
    public function __construct(
        public bool $allowed,
        public ?string $reason,
        public string $refinedQuery
    ) {
    }
}
