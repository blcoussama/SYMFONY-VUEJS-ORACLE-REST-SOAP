<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Incident;
use App\Enum\IncidentEventType;
use App\Enum\IncidentStatus;
use App\Service\Actor;
use App\Service\ActorResolver;
use App\Service\IncidentJournal;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\EnteredEvent;

/**
 * Effets de bord des transitions : horodatages (résolu, clos, réouverture)
 * et une entrée TRANSITION dans le journal pour chaque transition appliquée.
 */
final class IncidentWorkflowSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly IncidentJournal $journal,
        private readonly ActorResolver $actorResolver,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.incident.entered' => 'onEntered',
            'workflow.incident.completed' => 'onCompleted',
        ];
    }

    public function onEntered(EnteredEvent $event): void
    {
        $incident = $event->getSubject();
        if (!$incident instanceof Incident) {
            return;
        }

        $now = $this->clock->now();
        match ($event->getTransition()?->getName()) {
            IncidentTransition::RESOUDRE => $incident->markResolved($now),
            IncidentTransition::CLORE => $incident->markClosed($now),
            IncidentTransition::ROUVRIR => $incident->markReopened($now),
            default => $incident->touch($now),
        };
    }

    public function onCompleted(CompletedEvent $event): void
    {
        $incident = $event->getSubject();
        $transition = $event->getTransition();
        if (!$incident instanceof Incident || null === $transition) {
            return;
        }

        $context = $event->getContext();
        $actor = ($context['actor'] ?? null) instanceof Actor ? $context['actor'] : $this->actorResolver->current();
        $comment = \is_string($context['comment'] ?? null) ? $context['comment'] : null;

        $this->journal->record(
            $incident,
            IncidentEventType::Transition,
            $actor,
            $comment,
            IncidentStatus::from($transition->getFroms()[0]),
            IncidentStatus::from($transition->getTos()[0]),
        );
    }
}
