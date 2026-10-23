<?php

declare(strict_types=1);

namespace App\Enum;

enum IncidentEventType: string
{
    case Creation = 'CREATION';
    case Transition = 'TRANSITION';
    case Assignation = 'ASSIGNATION';
    case Commentaire = 'COMMENTAIRE';
    case Priorite = 'PRIORITE';

    public function label(): string
    {
        return match ($this) {
            self::Creation => 'Création',
            self::Transition => 'Changement de statut',
            self::Assignation => 'Assignation',
            self::Commentaire => 'Commentaire',
            self::Priorite => 'Priorité',
        };
    }
}
