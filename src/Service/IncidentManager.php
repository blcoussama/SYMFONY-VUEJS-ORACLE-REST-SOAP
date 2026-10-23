<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\IncidentDraft;
use App\Entity\Incident;
use App\Entity\IncidentEvent;
use App\Entity\Intervention;
use App\Entity\User;
use App\Enum\IncidentEventType;
use App\Enum\IncidentPriority;
use App\Enum\IncidentStatus;
use App\Enum\UserRole;
use App\Exception\BusinessRuleException;
use App\Exception\IncidentConflictException;
use App\Exception\TransitionRefusedException;
use App\Exception\UnknownTransitionException;
use App\Service\Reference\IncidentReferenceGenerator;
use App\Service\Sla\SlaPolicy;
use App\Workflow\IncidentGuardListener;
use App\Workflow\IncidentTransition;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Workflow\TransitionBlockerList;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Cas d'usage d'un incident : déclaration, transitions, (ré)assignation, priorité, commentaires.
 *
 * Toutes les vérifications (version, gardes du Workflow, données) sont faites AVANT toute
 * écriture ; les écritures sont ensuite regroupées dans une transaction.
 */
final class IncidentManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IncidentReferenceGenerator $references,
        private readonly SlaPolicy $slaPolicy,
        private readonly ClockInterface $clock,
        private readonly WorkflowInterface $incidentStateMachine,
        private readonly IncidentJournal $journal,
        private readonly InterventionManager $interventions,
    ) {
    }

    /** Le brouillon doit avoir été validé (formulaire, API ou SOAP). */
    public function declare(IncidentDraft $draft, Actor $actor): Incident
    {
        if (null === $draft->site || null === $draft->category || null === $draft->priority || null === $draft->title || null === $draft->description) {
            throw new \InvalidArgumentException('Brouillon d’incident incomplet.');
        }
        $site = $draft->site;
        $category = $draft->category;
        $priority = $draft->priority;
        $title = $draft->title;
        $description = $draft->description;

        return $this->entityManager->wrapInTransaction(function () use ($site, $category, $priority, $title, $description, $actor): Incident {
            $now = $this->clock->now();
            $incident = new Incident(
                $this->references->next($now),
                $title,
                $description,
                $site,
                $category,
                $priority,
                $actor->channel,
                $now,
                $this->slaPolicy->dueAt($priority, $now),
            );
            $incident->declaredBy($actor->user, $actor->client);
            $this->entityManager->persist($incident);
            $this->journal->record($incident, IncidentEventType::Creation, $actor, \sprintf('Incident déclaré (%s), priorité %s.', $actor->channel->label(), $priority->value), null, IncidentStatus::Nouveau);

            return $incident;
        });
    }

    /**
     * Applique une transition du Workflow. Données complémentaires ($input) : « report » (compte
     * rendu, pour résoudre), « priority » (priorité confirmée, pour qualifier), « assignee »
     * (technicien, pour assigner).
     *
     * @param array{report?: string|null, priority?: IncidentPriority|null, assignee?: User|null} $input
     *
     * @throws UnknownTransitionException
     * @throws TransitionRefusedException
     * @throws IncidentConflictException
     * @throws BusinessRuleException
     */
    public function applyTransition(Incident $incident, string $transition, Actor $actor, ?string $comment = null, ?int $expectedVersion = null, array $input = []): void
    {
        if (!IncidentTransition::exists($transition)) {
            throw new UnknownTransitionException($transition);
        }
        $this->assertVersion($incident, $expectedVersion);

        $report = trim((string) ($input['report'] ?? ''));
        $report = '' === $report ? null : $report;
        $blockers = $this->incidentStateMachine->buildTransitionBlockerList($incident, $transition);
        $remaining = $this->withoutSatisfiedBlockers($blockers, null !== $report);
        if (!$remaining->isEmpty()) {
            throw new TransitionRefusedException($transition, $remaining);
        }

        $assignee = $input['assignee'] ?? null;
        if (IncidentTransition::ASSIGNER === $transition) {
            $assignee ??= $incident->getAssignee();
            if (null === $assignee) {
                throw new BusinessRuleException('Choisissez le technicien à assigner.', 'assignee');
            }
            $this->assertTechnician($assignee);
        }
        if (IncidentTransition::RESOUDRE === $transition && null !== $report && null === $actor->user) {
            throw new BusinessRuleException('Seul le technicien peut saisir le compte rendu.', 'report');
        }
        if (null !== $report && mb_strlen($report) > Intervention::REPORT_MAX_LENGTH) {
            throw new BusinessRuleException('Le compte rendu dépasse 2000 caractères.', 'report');
        }
        $comment = $this->normalizeComment($comment);
        $priority = $input['priority'] ?? null;

        $this->write(function () use ($incident, $transition, $actor, $comment, $report, $assignee, $priority): void {
            if (IncidentTransition::QUALIFIER === $transition && null !== $priority && $priority !== $incident->getPriority()) {
                $this->reprioritize($incident, $priority, $actor, null);
            }
            if (IncidentTransition::ASSIGNER === $transition && null !== $assignee) {
                $incident->assignTo($assignee, $this->clock->now());
                $this->journal->record($incident, IncidentEventType::Assignation, $actor, \sprintf('Assigné à %s.', $assignee->getFullName()));
            }
            if (IncidentTransition::RESOUDRE === $transition && null !== $report && null !== $actor->user) {
                $this->interventions->recordReport($incident, $actor->user, $report);
            }
            $this->incidentStateMachine->apply($incident, $transition, ['actor' => $actor, 'comment' => $comment]);
        });
    }

    /**
     * Assigne (QUALIFIE → ASSIGNE) ou réassigne un incident en cours de traitement.
     * La réassignation n'est pas une transition : droit vérifié par IncidentVoter::ASSIGN.
     */
    public function assign(Incident $incident, User $technician, Actor $actor, ?string $comment = null, ?int $expectedVersion = null): void
    {
        if (IncidentStatus::Qualifie === $incident->getStatus()) {
            $this->applyTransition($incident, IncidentTransition::ASSIGNER, $actor, $comment, $expectedVersion, ['assignee' => $technician]);

            return;
        }
        if (!\in_array($incident->getStatus(), [IncidentStatus::Assigne, IncidentStatus::EnCours, IncidentStatus::EnAttente], true)) {
            throw new TransitionRefusedException(IncidentTransition::ASSIGNER, 'Cet incident ne peut pas être assigné dans son statut actuel.');
        }
        $this->assertVersion($incident, $expectedVersion);
        $this->assertTechnician($technician);
        if ($incident->isAssignedTo($technician)) {
            return;
        }

        $previous = $incident->getAssignee();
        $comment = $this->normalizeComment($comment);
        $this->write(function () use ($incident, $technician, $previous, $actor, $comment): void {
            $incident->assignTo($technician, $this->clock->now());
            $text = null !== $previous
                ? \sprintf('Réassigné de %s à %s.', $previous->getFullName(), $technician->getFullName())
                : \sprintf('Assigné à %s.', $technician->getFullName());
            $this->journal->record($incident, IncidentEventType::Assignation, $actor, null !== $comment ? $text.' '.$comment : $text);
        });
    }

    /** Change la priorité d'un incident ouvert ; l'échéance SLA est recalculée. */
    public function changePriority(Incident $incident, IncidentPriority $priority, Actor $actor, ?string $comment = null, ?int $expectedVersion = null): void
    {
        if (!$incident->isOpen()) {
            throw new TransitionRefusedException('prioriser', 'La priorité d’un incident terminé ne peut plus changer.');
        }
        $this->assertVersion($incident, $expectedVersion);
        if ($priority === $incident->getPriority()) {
            return;
        }
        $comment = $this->normalizeComment($comment);
        $this->write(function () use ($incident, $priority, $actor, $comment): void {
            $this->reprioritize($incident, $priority, $actor, $comment);
        });
    }

    public function comment(Incident $incident, string $text, Actor $actor): IncidentEvent
    {
        $text = $this->normalizeComment($text) ?? throw new BusinessRuleException('Le commentaire est vide.', 'comment');
        $event = $this->journal->record($incident, IncidentEventType::Commentaire, $actor, $text);
        $this->entityManager->flush();

        return $event;
    }

    private function reprioritize(Incident $incident, IncidentPriority $priority, Actor $actor, ?string $comment): void
    {
        $old = $incident->getPriority();
        $dueAt = $this->slaPolicy->dueAt($priority, $incident->getCreatedAt());
        $incident->reprioritize($priority, $dueAt, $this->clock->now());
        $text = \sprintf('Priorité %s → %s ; nouvelle échéance SLA : %s.', $old->value, $priority->value, $dueAt->setTimezone(new \DateTimeZone('Africa/Casablanca'))->format('d/m/Y H:i'));
        $this->journal->record($incident, IncidentEventType::Priorite, $actor, null !== $comment ? $text.' '.$comment : $text);
    }

    /**
     * @param callable(): void $operation
     */
    private function write(callable $operation): void
    {
        try {
            $this->entityManager->wrapInTransaction($operation);
        } catch (OptimisticLockException $e) {
            throw new IncidentConflictException(previous: $e);
        }
    }

    private function assertVersion(Incident $incident, ?int $expectedVersion): void
    {
        if (null !== $expectedVersion && $expectedVersion !== $incident->getVersion()) {
            throw new IncidentConflictException();
        }
    }

    private function assertTechnician(User $user): void
    {
        if (UserRole::Technicien !== $user->getRole() || !$user->isActive()) {
            throw new BusinessRuleException('Le technicien choisi est introuvable ou inactif.', 'assignee');
        }
    }

    private function normalizeComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);
        if ('' === $comment) {
            return null;
        }
        if (mb_strlen($comment) > IncidentEvent::COMMENT_MAX_LENGTH) {
            throw new BusinessRuleException(\sprintf('Le commentaire dépasse %d caractères.', IncidentEvent::COMMENT_MAX_LENGTH), 'comment');
        }

        return $comment;
    }

    private function withoutSatisfiedBlockers(TransitionBlockerList $blockers, bool $reportProvided): TransitionBlockerList
    {
        if (!$reportProvided) {
            return $blockers;
        }

        $remaining = new TransitionBlockerList();
        foreach ($blockers as $blocker) {
            if (IncidentGuardListener::CODE_REPORT_REQUIRED !== $blocker->getCode()) {
                $remaining->add($blocker);
            }
        }

        return $remaining;
    }
}
