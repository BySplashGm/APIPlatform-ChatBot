<?php

namespace App\Service\Benchmark\TestGenerator;

class RobustnessTestGenerator implements TestGeneratorInterface
{
    public function __construct(
        private bool $lightMode = false
    ) {
    }

    public function generateVariations(string $question): array
    {
        if ($this->lightMode) {
            return [
                'lowercase' => strtolower($question),
                'uppercase' => strtoupper($question),
            ];
        }
        
        return [
            'lowercase' => strtolower($question),
            'uppercase' => strtoupper($question),
            'add_typo' => $this->addTypo($question),
            'strip_punctuation' => preg_replace('/[^\w\s]/', '', $question),
            'add_punctuation' => $question . '???',
        ];
    }

    public function getTestSuiteName(): string
    {
        return 'robustness';
    }

    private function addTypo(string $text): string
    {
        $words = explode(' ', $text);
        if (count($words) < 2) {
            return $text;
        }
        
        $targetIndex = rand(0, count($words) - 1);
        $word = $words[$targetIndex];
        
        if (strlen($word) > 3) {
            $pos = rand(1, strlen($word) - 2);
            $word = substr_replace($word, substr($word, $pos, 1) . substr($word, $pos - 1, 1), $pos - 1, 2);
            $words[$targetIndex] = $word;
        }
        
        return implode(' ', $words);
    }
}
