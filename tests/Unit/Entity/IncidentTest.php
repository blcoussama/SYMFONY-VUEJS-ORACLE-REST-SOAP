<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IncidentEvent;
use App\Enum\IncidentEventType;
use App\Enum\IncidentStatus;
use App\Enum\UserRole;
use App\Tests\Support\DomainFactory;
use PHPUnit\Framework\TestCase;

final class IncidentTest extends TestCase
{
    public function testBreachedOnlyWhenOpenAfterTheDeadline(): void
    {
        $incident = DomainFactory::incident(IncidentStatus::EnCours);
        $afterDue = $incident->getSlaDueAt()->modify('+1 minute');

        self::assertFalse($incident->isSlaBreached($incident->getSlaDueAt()));
        self::assertTrue($incident->isSlaBreached($afterDue));

        $incident->setStatus(IncidentStatus::Resolu);
        self::assertFalse($incident->isSlaBreached($afterDue));
    }

    public function testAssignmentComparesUsersByIdentity(): void
    {
        $tech = DomainFactory::user(UserRole::Technicien);
        $incident = DomainFactory::incident(IncidentStatus::Assigne, $tech);

        self::assertTrue($incident->isAssignedTo($tech));
        self::assertFalse($incident->isAssignedTo(DomainFactory::user(UserRole::Technicien)));
        self::assertFalse($incident->isAssignedTo(null));
    }

    public function testReporterLabel(): void
    {
        $client = DomainFactory::apiClient();

        self::assertSame('Partenaire : '.$client->getName(), DomainFactory::incident(IncidentStatus::Nouveau, null, $client)->getReporterLabel());
        self::assertSame('Système', DomainFactory::incident()->getReporterLabel());
    }

    public function testReopeningClearsTheResolutionDate(): void
    {
        $incident = DomainFactory::incident(IncidentStatus::Resolu);
        $incident->markResolved(new \DateTimeImmutable('2026-03-10 10:00'));
        $incident->markReopened(new \DateTimeImmutable('2026-03-10 11:00'));

        self::assertNull($incident->getResolvedAt());
        self::assertSame('2026-03-10 11:00', $incident->getUpdatedAt()->format('Y-m-d H:i'));
    }

    public function testEventStoresEmptyCommentAsNullForOracle(): void
    {
        $incident = DomainFactory::incident();
        $event = new IncidentEvent($incident, IncidentEventType::Commentaire, '  ', new \DateTimeImmutable(), null, null, null, '   ');

        self::assertNull($event->getComment());
        self::assertSame('Système', $event->getAuthorLabel());
        self::assertTrue($incident->getEvents()->contains($event));
    }
}
