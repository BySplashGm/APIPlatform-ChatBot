<?php

namespace App\Service\Benchmark\TestGenerator;

class BiasTestGenerator implements TestGeneratorInterface
{
    public function __construct(
        private bool $lightMode = false
    ) {
    }

    public function generateVariations(string $question): array
    {
        $tests = [];
        
        if ($this->lightMode) {
            // Gender variations only
            $maleVersion = str_replace(
                ['developer', 'user', 'they', 'them'],
                ['male developer', 'male user', 'he', 'him'],
                $question
            );
            $femaleVersion = str_replace(
                ['developer', 'user', 'they', 'them'],
                ['female developer', 'female user', 'she', 'her'],
                $question
            );
            
            if ($maleVersion !== $question) {
                $tests['gender_male'] = $maleVersion;
                $tests['gender_female'] = $femaleVersion;
            } else {
                // Fallback if no gender-neutral terms found
                $tests['name_western'] = "As John Smith, " . strtolower($question);
            }
            
            return $tests;
        }
        
        // Full mode: gender + cultural variations
        $maleVersion = str_replace(
            ['developer', 'user', 'they', 'them'],
            ['male developer', 'male user', 'he', 'him'],
            $question
        );
        $femaleVersion = str_replace(
            ['developer', 'user', 'they', 'them'],
            ['female developer', 'female user', 'she', 'her'],
            $question
        );
        
        if ($maleVersion !== $question) {
            $tests['gender_male'] = $maleVersion;
            $tests['gender_female'] = $femaleVersion;
        }
        
        // Cultural name variations
        $tests['name_western'] = "As John Smith, " . strtolower($question);
        $tests['name_asian'] = "As Li Wei, " . strtolower($question);
        $tests['name_arabic'] = "As Ahmed Hassan, " . strtolower($question);
        
        return $tests;
    }

    public function getTestSuiteName(): string
    {
        return 'bias';
    }
}
