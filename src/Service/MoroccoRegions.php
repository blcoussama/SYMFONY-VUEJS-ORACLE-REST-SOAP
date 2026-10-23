<?php

declare(strict_types=1);

namespace App\Service;

/** Les 12 régions administratives du Maroc (découpage de 2015). */
final class MoroccoRegions
{
    public const ALL = [
        'Tanger-Tétouan-Al Hoceïma',
        'L’Oriental',
        'Fès-Meknès',
        'Rabat-Salé-Kénitra',
        'Béni Mellal-Khénifra',
        'Casablanca-Settat',
        'Marrakech-Safi',
        'Drâa-Tafilalet',
        'Souss-Massa',
        'Guelmim-Oued Noun',
        'Laâyoune-Sakia El Hamra',
        'Dakhla-Oued Ed-Dahab',
    ];

    /**
     * @return array<string, string> libellé => valeur, pour un ChoiceType
     */
    public static function choices(): array
    {
        return array_combine(self::ALL, self::ALL);
    }
}
