<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Incident;
use App\Service\Sla\DurationFormatter;
use App\Service\Sla\SlaAssessment;
use App\Service\Sla\SlaPolicy;
use App\Workflow\IncidentActions;
use Psr\Clock\ClockInterface;
use Twig\Attribute\AsTwigFunction;

/** Fonctions Twig propres à NetOps (SLA, actions du Workflow, durées). */
final class AppExtension
{
    public function __construct(
        private readonly SlaPolicy $slaPolicy,
        private readonly ClockInterface $clock,
        private readonly IncidentActions $actions,
    ) {
    }

    #[AsTwigFunction('sla')]
    public function sla(Incident $incident): SlaAssessment
    {
        return $this->slaPolicy->assessIncident($incident, $this->clock->now());
    }

    /**
     * @return list<string>
     */
    #[AsTwigFunction('incident_actions')]
    public function incidentActions(Incident $incident): array
    {
        return $this->actions->available($incident);
    }

    #[AsTwigFunction('transition_label')]
    public function transitionLabel(string $transition): string
    {
        return $this->actions->label($transition);
    }

    #[AsTwigFunction('duration')]
    public function duration(int|float|null $minutes): string
    {
        return DurationFormatter::formatMinutes(null === $minutes ? null : (float) $minutes);
    }

    /**
     * @return array<string, int>
     */
    #[AsTwigFunction('sla_hours')]
    public function slaHours(): array
    {
        return $this->slaPolicy->allHours();
    }
}
