<?php

namespace App\Service\Benchmark\TestGenerator;

class ContextNoiseTestGenerator implements TestGeneratorInterface
{
    public function __construct(
        private bool $lightMode = false
    ) {
    }

    public function generateVariations(string $question): array
    {
        $noise1 = "The weather is nice today and I like pizza. ";
        $noise2 = " By the way, did you know that cats are amazing creatures?";
        $noise3 = "Random fact: The Eiffel Tower is in Paris. ";
        
        if ($this->lightMode) {
            return [
                'noise_prefix' => $noise1 . $question,
            ];
        }
        
        return [
            'noise_prefix' => $noise1 . $question,
            'noise_suffix' => $question . $noise2,
            'noise_both' => $noise3 . $question . $noise2,
        ];
    }

    public function getTestSuiteName(): string
    {
        return 'context_noise';
    }
}
