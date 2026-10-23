<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Incident;
use App\Entity\Intervention;
use App\Entity\User;
use App\Enum\IncidentEventType;
use App\Enum\IncidentStatus;
use App\Exception\BusinessRuleException;
use App\Service\Sla\DurationFormatter;
use App\Workflow\IncidentTransition;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Interventions des techniciens : planifier, démarrer (démarre aussi l'incident), terminer avec compte rendu.
 * Les droits (technicien assigné, intervention non terminée) sont vérifiés par les Voters côté contrôleur.
 */
final class InterventionManager
{
    private const WORKABLE = [IncidentStatus::Assigne, IncidentStatus::EnCours, IncidentStatus::EnAttente];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly WorkflowInterface $incidentStateMachine,
        private readonly IncidentJournal $journal,
    ) {
    }

    public function plan(Incident $incident, User $technician, \DateTimeImmutable $plannedAt, Actor $actor): Intervention
    {
        if (!\in_array($incident->getStatus(), self::WORKABLE, true) || !$incident->isAssignedTo($technician)) {
            throw new BusinessRuleException('Seul un incident assigné à ce technicien peut recevoir une intervention.', 'incident');
        }

        $now = $this->clock->now();
        $intervention = new Intervention($incident, $technician, $plannedAt, $now);
        $this->entityManager->persist($intervention);
        $incident->touch($now);
        $this->journal->record($incident, IncidentEventType::Commentaire, $actor, \sprintf('Intervention planifiée le %s (heure du Maroc).', self::localDate($plannedAt)));
        $this->entityManager->flush();

        return $intervention;
    }

    /**
     * Démarre l'intervention ; l'incident passe EN_COURS (transition « démarrer » ou « reprendre »).
     */
    public function start(Intervention $intervention, Actor $actor): void
    {
        $incident = $intervention->getIncident();
        if (!\in_array($incident->getStatus(), self::WORKABLE, true)) {
            throw new BusinessRuleException('L’incident n’est plus ouvert à une intervention.', 'incident');
        }

        $this->entityManager->wrapInTransaction(function () use ($intervention, $incident, $actor): void {
            $intervention->start($this->clock->now());
            $transition = match ($incident->getStatus()) {
                IncidentStatus::Assigne => IncidentTransition::DEMARRER,
                IncidentStatus::EnAttente => IncidentTransition::REPRENDRE,
                default => null,
            };
            if (null !== $transition && $this->incidentStateMachine->can($incident, $transition)) {
                $this->incidentStateMachine->apply($incident, $transition, ['actor' => $actor, 'comment' => 'Intervention démarrée.']);
            }
        });
    }

    public function finish(Intervention $intervention, string $report, Actor $actor): void
    {
        $intervention->finish($this->clock->now(), $report);
        $incident = $intervention->getIncident();
        $incident->touch($this->clock->now());
        $this->journal->record($incident, IncidentEventType::Commentaire, $actor, \sprintf(
            'Intervention terminée (durée : %s).',
            DurationFormatter::format(60 * (int) $intervention->getDurationMinutes()),
        ));
        $this->entityManager->flush();
    }

    /**
     * Compte rendu saisi au moment de « résoudre » : termine l'intervention en cours du technicien,
     * ou en crée une, débutée à la dernière mise EN_COURS de l'incident. L'appelant fait le flush.
     */
    public function recordReport(Incident $incident, User $technician, string $report): Intervention
    {
        $now = $this->clock->now();
        $intervention = $incident->findOpenInterventionOf($technician);
        if (null === $intervention) {
            $startedAt = $this->lastStartOf($incident) ?? $now;
            $intervention = new Intervention($incident, $technician, $startedAt, $now);
            $intervention->start($startedAt);
            $this->entityManager->persist($intervention);
        }
        $intervention->finish($now, $report);

        return $intervention;
    }

    private function lastStartOf(Incident $incident): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($incident->getEvents() as $event) {
            if (IncidentEventType::Transition === $event->getType() && IncidentStatus::EnCours === $event->getToStatus()) {
                $last = $event->getCreatedAt();
            }
        }

        return $last;
    }

    private static function localDate(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('Africa/Casablanca'))->format('d/m/Y à H:i');
    }
}
