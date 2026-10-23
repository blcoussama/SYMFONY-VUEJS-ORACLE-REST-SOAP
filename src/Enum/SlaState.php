<?php

declare(strict_types=1);

namespace App\Enum;

enum SlaState: string
{
    /** Ouvert, échéance lointaine. */
    case OnTrack = 'ON_TRACK';
    /** Ouvert, moins de 25 % du délai restant. */
    case AtRisk = 'AT_RISK';
    /** Ouvert et échéance dépassée : « en dépassement ». */
    case Breached = 'BREACHED';
    /** Résolu avant l'échéance. */
    case Met = 'MET';
    /** Résolu après l'échéance. */
    case Missed = 'MISSED';
    /** Incident annulé : pas de SLA. */
    case NotApplicable = 'NOT_APPLICABLE';

    public function badgeClass(): string
    {
        return match ($this) {
            self::OnTrack, self::Met => 'text-bg-success',
            self::AtRisk => 'text-bg-warning',
            self::Breached, self::Missed => 'text-bg-danger',
            self::NotApplicable => 'text-bg-light border',
        };
    }
}
