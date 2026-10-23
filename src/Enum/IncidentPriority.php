<?php

declare(strict_types=1);

namespace App\Enum;

enum IncidentPriority: string
{
    case P1 = 'P1';
    case P2 = 'P2';
    case P3 = 'P3';
    case P4 = 'P4';

    public function label(): string
    {
        return match ($this) {
            self::P1 => 'P1 — Critique',
            self::P2 => 'P2 — Majeure',
            self::P3 => 'P3 — Mineure',
            self::P4 => 'P4 — Faible',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::P1 => 'text-bg-danger',
            self::P2 => 'text-bg-warning',
            self::P3 => 'text-bg-primary',
            self::P4 => 'text-bg-secondary',
        };
    }
}
