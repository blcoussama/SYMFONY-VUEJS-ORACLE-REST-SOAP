<?php

declare(strict_types=1);

namespace App\Workflow;

/**
 * Noms des transitions du Workflow « incident » et rôle qui peut les déclencher (SPEC §3).
 */
final class IncidentTransition
{
    public const QUALIFIER = 'qualifier';
    public const ASSIGNER = 'assigner';
    public const DEMARRER = 'demarrer';
    public const METTRE_EN_ATTENTE = 'mettre_en_attente';
    public const REPRENDRE = 'reprendre';
    public const RESOUDRE = 'resoudre';
    public const CLORE = 'clore';
    public const ROUVRIR = 'rouvrir';
    public const ANNULER = 'annuler';

    /** Transitions réservées au superviseur. */
    public const SUPERVISOR = [self::QUALIFIER, self::ASSIGNER, self::CLORE, self::ROUVRIR, self::ANNULER];

    /** Transitions réservées au technicien assigné. */
    public const ASSIGNED_TECHNICIAN = [self::DEMARRER, self::METTRE_EN_ATTENTE, self::REPRENDRE, self::RESOUDRE];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::SUPERVISOR, ...self::ASSIGNED_TECHNICIAN];
    }

    public static function exists(string $name): bool
    {
        return \in_array($name, self::all(), true);
    }

    public static function isSupervisorTransition(string $name): bool
    {
        return \in_array($name, self::SUPERVISOR, true);
    }

    public static function isTechnicianTransition(string $name): bool
    {
        return \in_array($name, self::ASSIGNED_TECHNICIAN, true);
    }
}
