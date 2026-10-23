<?php

declare(strict_types=1);

namespace App\Api\Normalizer;

use App\Entity\Site;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class SiteNormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, string|bool>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof Site);

        return self::toArray($data);
    }

    /**
     * @return array{code: string, name: string, city: string, region: string, type: string, active: bool}
     */
    public static function toArray(Site $site): array
    {
        return [
            'code' => $site->getCode(),
            'name' => $site->getName(),
            'city' => $site->getCity(),
            'region' => $site->getRegion(),
            'type' => $site->getType()->value,
            'active' => $site->isActive(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Site;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Site::class => true];
    }
}
