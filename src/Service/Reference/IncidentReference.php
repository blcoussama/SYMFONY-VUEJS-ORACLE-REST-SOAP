<?php

declare(strict_types=1);

namespace App\Service\Reference;

/**
 * Format des références lisibles « INC-AAAA-NNNNNN » (numéro séquentiel annuel sur 6 chiffres).
 */
final class IncidentReference
{
    public const PREFIX = 'INC';
    public const PATTERN = '/^INC-(\d{4})-(\d{6})$/';
    public const MAX_SEQUENCE = 999_999;

    public static function format(int $year, int $sequence): string
    {
        if ($year < 2000 || $year > 9999) {
            throw new \InvalidArgumentException(\sprintf('Année hors limites : %d.', $year));
        }
        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new \OverflowException(\sprintf('Numéro de séquence hors limites : %d (1 à %d).', $sequence, self::MAX_SEQUENCE));
        }

        return \sprintf('%s-%04d-%06d', self::PREFIX, $year, $sequence);
    }

    public static function isValid(string $reference): bool
    {
        return 1 === preg_match(self::PATTERN, $reference) && '000000' !== substr($reference, -6);
    }

    /**
     * @return array{year: int, sequence: int}
     */
    public static function parse(string $reference): array
    {
        if (!self::isValid($reference)) {
            throw new \InvalidArgumentException(\sprintf('Référence d’incident invalide : « %s ».', $reference));
        }

        return ['year' => (int) substr($reference, 4, 4), 'sequence' => (int) substr($reference, 9, 6)];
    }

    /** Clé du compteur annuel en base (table nop_ref_counter). */
    public static function counterKey(int $year): string
    {
        return \sprintf('%s-%04d', self::PREFIX, $year);
    }
}
