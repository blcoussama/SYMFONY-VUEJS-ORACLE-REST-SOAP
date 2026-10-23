<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Génération et empreinte des clés d'API partenaires.
 *
 * Format : « nop_ » + 64 caractères hexadécimaux (32 octets aléatoires).
 */
final class ApiKeyHasher
{
    public const PREFIX = 'nop_';

    /**
     * @return array{key: string, prefix: string, hash: string}
     */
    public function generate(): array
    {
        $key = self::PREFIX.bin2hex(random_bytes(32));

        return ['key' => $key, 'prefix' => self::displayPrefix($key), 'hash' => self::hash($key)];
    }

    public static function hash(string $key): string
    {
        return hash('sha256', trim($key));
    }

    /** Début de clé affichable (« nop_1a2b3c4d… »). */
    public static function displayPrefix(string $key): string
    {
        return substr(trim($key), 0, 12);
    }
}
