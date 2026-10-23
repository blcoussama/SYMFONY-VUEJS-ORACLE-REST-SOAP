<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\IncidentPriority;
use App\Enum\IncidentStatus;
use App\Enum\SlaState;
use App\Service\Sla\SlaPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlaPolicyTest extends TestCase
{
    private SlaPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SlaPolicy(['P1' => 4, 'P2' => 8, 'P3' => 24, 'P4' => 72]);
    }

    /**
     * @return iterable<string, array{IncidentPriority, string}>
     */
    public static function dueDates(): iterable
    {
        yield 'P1 = 4 h' => [IncidentPriority::P1, '2026-03-10 12:30:00'];
        yield 'P2 = 8 h' => [IncidentPriority::P2, '2026-03-10 16:30:00'];
        yield 'P3 = 24 h' => [IncidentPriority::P3, '2026-03-11 08:30:00'];
        yield 'P4 = 72 h' => [IncidentPriority::P4, '2026-03-13 08:30:00'];
    }

    #[DataProvider('dueDates')]
    public function testDueDateIsCreationPlusPriorityDelay(IncidentPriority $priority, string $expected): void
    {
        $due = $this->policy->dueAt($priority, new \DateTimeImmutable('2026-03-10 08:30:00', new \DateTimeZone('UTC')));

        self::assertSame($expected, $due->format('Y-m-d H:i:s'));
    }

    public function testDelaysAreConfigurable(): void
    {
        $policy = new SlaPolicy(['P1' => '2', 'P2' => 6, 'P3' => 12, 'P4' => 48]);

        self::assertSame(2, $policy->hoursFor(IncidentPriority::P1));
        self::assertSame(['P1' => 2, 'P2' => 6, 'P3' => 12, 'P4' => 48], $policy->allHours());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'priorité manquante' => [['P1' => 4, 'P2' => 8, 'P3' => 24]];
        yield 'délai nul' => [['P1' => 0, 'P2' => 8, 'P3' => 24, 'P4' => 72]];
        yield 'délai non numérique' => [['P1' => 'quatre', 'P2' => 8, 'P3' => 24, 'P4' => 72]];
        yield 'délai supérieur à un an' => [['P1' => 9000, 'P2' => 8, 'P3' => 24, 'P4' => 72]];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRejected(array $configuration): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SlaPolicy($configuration);
    }

    /**
     * @return iterable<string, array{IncidentStatus, string, ?string, string, SlaState}>
     */
    public static function assessments(): iterable
    {
        // statut, maintenant, résolu le, libellé attendu, état — création 08:00, échéance 12:00 (P1)
        yield 'ouvert, large marge' => [IncidentStatus::EnCours, '09:00', null, 'Reste 3 h', SlaState::OnTrack];
        yield 'ouvert, moins de 25 % restant' => [IncidentStatus::EnCours, '11:15', null, 'Reste 45 min', SlaState::AtRisk];
        yield 'ouvert, échéance exacte' => [IncidentStatus::Assigne, '12:00', null, 'Reste moins d’une minute', SlaState::AtRisk];
        yield 'ouvert, dépassé' => [IncidentStatus::EnAttente, '13:30', null, 'Dépassé de 1 h 30 min', SlaState::Breached];
        yield 'résolu à temps' => [IncidentStatus::Resolu, '20:00', '11:59', 'SLA respecté', SlaState::Met];
        yield 'clos, résolu en retard' => [IncidentStatus::Clos, '20:00', '14:00', 'SLA non respecté (+2 h)', SlaState::Missed];
        yield 'annulé' => [IncidentStatus::Annule, '20:00', null, 'Sans objet', SlaState::NotApplicable];
    }

    #[DataProvider('assessments')]
    public function testAssessment(IncidentStatus $status, string $now, ?string $resolvedAt, string $label, SlaState $state): void
    {
        $day = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-03-10 '.$time.':00', new \DateTimeZone('UTC'));
        $created = $day('08:00');

        $assessment = $this->policy->assess($status, $created, $this->policy->dueAt(IncidentPriority::P1, $created), null !== $resolvedAt ? $day($resolvedAt) : null, $day($now));

        self::assertSame($state, $assessment->state);
        self::assertSame($label, $assessment->label());
        self::assertSame(SlaState::Breached === $state, $assessment->isBreached());
    }

    public function testWaitingTimeDoesNotSuspendTheSla(): void
    {
        $created = new \DateTimeImmutable('2026-03-10 08:00:00');
        $due = $this->policy->dueAt(IncidentPriority::P2, $created);

        // Simplification documentée : 6 h EN_ATTENTE ne décalent pas l'échéance.
        $assessment = $this->policy->assess(IncidentStatus::EnAttente, $created, $due, null, $created->modify('+9 hours'));

        self::assertSame(SlaState::Breached, $assessment->state);
        self::assertSame(-3600, $assessment->secondsLeft);
    }

    public function testReopenedIncidentKeepsItsOriginalDeadline(): void
    {
        $created = new \DateTimeImmutable('2026-03-10 08:00:00');
        $due = $this->policy->dueAt(IncidentPriority::P1, $created);

        // Réouvert (ASSIGNE, resolvedAt remis à null) après l'échéance : en dépassement.
        $assessment = $this->policy->assess(IncidentStatus::Assigne, $created, $due, null, $created->modify('+5 hours'));

        self::assertTrue($assessment->isBreached());
    }
}
