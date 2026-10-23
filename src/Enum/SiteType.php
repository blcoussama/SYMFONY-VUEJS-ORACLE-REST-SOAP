<?php

declare(strict_types=1);

namespace App\Enum;

enum SiteType: string
{
    case Antenne4G = 'ANTENNE_4G';
    case Antenne5G = 'ANTENNE_5G';
    case Central = 'CENTRAL';
    case FibreNro = 'FIBRE_NRO';
    case Datacenter = 'DATACENTER';

    public function label(): string
    {
        return match ($this) {
            self::Antenne4G => 'Antenne 4G',
            self::Antenne5G => 'Antenne 5G',
            self::Central => 'Central téléphonique',
            self::FibreNro => 'NRO fibre',
            self::Datacenter => 'Datacenter',
        };
    }
}
