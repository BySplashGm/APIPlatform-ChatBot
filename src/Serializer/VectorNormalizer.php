<?php

namespace App\Serializer;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class VectorNormalizer implements NormalizerInterface
{
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        /** @var Vector $object */
        $reflection = new \ReflectionClass($object);
        
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($object);
            
            if (is_string($value)) {
                return ['prompt' => $value, 'input' => $value];
            }
        }
        
        return ['prompt' => ''];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Vector;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Vector::class => true,
        ];
    }
}