<?php

declare(strict_types=1);

namespace App\Service\Sla;

use App\Enum\SlaState;

/**
 * Résultat de l'évaluation SLA d'un incident à un instant donné.
 */
final readonly class SlaAssessment
{
    /**
     * @param int $secondsLeft secondes restantes avant l'échéance (négatif : dépassement) ;
     *                         pour un incident résolu, marge entre la résolution et l'échéance
     */
    public function __construct(
        public SlaState $state,
        public \DateTimeImmutable $dueAt,
        public int $secondsLeft,
    ) {
    }

    public function isBreached(): bool
    {
        return SlaState::Breached === $this->state;
    }

    public function label(): string
    {
        return match ($this->state) {
            SlaState::OnTrack, SlaState::AtRisk => 'Reste '.DurationFormatter::format($this->secondsLeft),
            SlaState::Breached => 'Dépassé de '.DurationFormatter::format(-$this->secondsLeft),
            SlaState::Met => 'SLA respecté',
            SlaState::Missed => 'SLA non respecté (+'.DurationFormatter::format(-$this->secondsLeft).')',
            SlaState::NotApplicable => 'Sans objet',
        };
    }
}
