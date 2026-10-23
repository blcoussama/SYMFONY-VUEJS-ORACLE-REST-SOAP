<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Incident;
use App\Entity\IncidentEvent;
use App\Enum\IncidentEventType;
use App\Enum\IncidentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Ajoute des entrées (immuables) au journal d'un incident. L'appelant fait le flush. */
final class IncidentJournal
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function record(
        Incident $incident,
        IncidentEventType $type,
        Actor $actor,
        ?string $comment = null,
        ?IncidentStatus $from = null,
        ?IncidentStatus $to = null,
    ): IncidentEvent {
        $event = new IncidentEvent($incident, $type, $actor->label(), $this->clock->now(), $actor->user, $from, $to, $comment);
        $this->entityManager->persist($event);

        return $event;
    }
}
