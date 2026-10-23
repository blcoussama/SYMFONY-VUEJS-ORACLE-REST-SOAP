<?php

declare(strict_types=1);

namespace App\Enum;

enum IncidentCategory: string
{
    case PanneEnergie = 'PANNE_ENERGIE';
    case CoupureFibre = 'COUPURE_FIBRE';
    case Equipement = 'EQUIPEMENT';
    case Radio = 'RADIO';
    case Logiciel = 'LOGICIEL';
    case Autre = 'AUTRE';

    public function label(): string
    {
        return match ($this) {
            self::PanneEnergie => 'Panne d’énergie',
            self::CoupureFibre => 'Coupure fibre',
            self::Equipement => 'Équipement',
            self::Radio => 'Radio',
            self::Logiciel => 'Logiciel',
            self::Autre => 'Autre',
        };
    }
}
