<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Rôle principal d'un utilisateur ; les droits hérités viennent de la
 * hiérarchie de rôles de security.yaml.
 */
enum UserRole: string
{
    case Agent = 'ROLE_AGENT';
    case Technicien = 'ROLE_TECHNICIEN';
    case Superviseur = 'ROLE_SUPERVISEUR';
    case Admin = 'ROLE_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::Agent => 'Agent NOC',
            self::Technicien => 'Technicien',
            self::Superviseur => 'Superviseur',
            self::Admin => 'Administrateur',
        };
    }
}
