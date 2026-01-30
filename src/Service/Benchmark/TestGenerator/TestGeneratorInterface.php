<?php

namespace App\Service\Benchmark\TestGenerator;

interface TestGeneratorInterface
{
    /**
     * Generate test variations for a given question
     * 
     * @param string $question
     * @return array<string, string> Map of variation type to modified question
     */
    public function generateVariations(string $question): array;

    /**
     * Get the test suite name
     * 
     * @return string
     */
    public function getTestSuiteName(): string;
}
