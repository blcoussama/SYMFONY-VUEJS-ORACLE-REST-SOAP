<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Intervention;
use App\Enum\IncidentStatus;
use App\Enum\UserRole;
use App\Tests\Support\DomainFactory;
use PHPUnit\Framework\TestCase;

final class InterventionTest extends TestCase
{
    private function intervention(): Intervention
    {
        $tech = DomainFactory::user(UserRole::Technicien);

        return new Intervention(DomainFactory::incident(IncidentStatus::Assigne, $tech), $tech, new \DateTimeImmutable('2026-03-10 09:00'), new \DateTimeImmutable('2026-03-10 08:00'));
    }

    public function testLifecycleAndComputedDuration(): void
    {
        $intervention = $this->intervention();
        self::assertTrue($intervention->isPlanned());

        $intervention->start(new \DateTimeImmutable('2026-03-10 09:10'));
        self::assertTrue($intervention->isInProgress());

        $intervention->finish(new \DateTimeImmutable('2026-03-10 11:05:59'), '  Carte remplacée.  ');
        self::assertTrue($intervention->isFinished());
        self::assertSame(115, $intervention->getDurationMinutes());
        self::assertSame('Carte remplacée.', $intervention->getReport());
        self::assertTrue($intervention->getIncident()->hasCompletedInterventionReport());
    }

    public function testCannotFinishBeforeStarting(): void
    {
        $this->expectException(\LogicException::class);

        $this->intervention()->finish(new \DateTimeImmutable(), 'Compte rendu');
    }

    public function testReportIsMandatory(): void
    {
        $intervention = $this->intervention();
        $intervention->start(new \DateTimeImmutable('2026-03-10 09:00'));

        $this->expectException(\InvalidArgumentException::class);
        $intervention->finish(new \DateTimeImmutable('2026-03-10 10:00'), '   ');
    }

    public function testReportIsLimitedTo2000Characters(): void
    {
        $intervention = $this->intervention();
        $intervention->start(new \DateTimeImmutable('2026-03-10 09:00'));

        $this->expectException(\InvalidArgumentException::class);
        $intervention->finish(new \DateTimeImmutable('2026-03-10 10:00'), str_repeat('é', 2001));
    }

    public function testEndCannotPrecedeStart(): void
    {
        $intervention = $this->intervention();
        $intervention->start(new \DateTimeImmutable('2026-03-10 09:00'));

        $this->expectException(\InvalidArgumentException::class);
        $intervention->finish(new \DateTimeImmutable('2026-03-10 08:59'), 'Compte rendu');
    }

    public function testStartedInterventionCannotBeRescheduled(): void
    {
        $intervention = $this->intervention();
        $intervention->start(new \DateTimeImmutable('2026-03-10 09:00'));

        $this->expectException(\LogicException::class);
        $intervention->reschedule(new \DateTimeImmutable('2026-03-11 09:00'));
    }
}
