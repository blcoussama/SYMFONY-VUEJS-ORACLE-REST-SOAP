<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Incident;
use App\Entity\Intervention;
use App\Enum\IncidentStatus;
use App\Enum\UserRole;
use App\Security\Voter\IncidentVoter;
use App\Tests\Support\DomainFactory;
use App\Workflow\IncidentGuardListener;
use App\Workflow\IncidentTransition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

final class IncidentGuardListenerTest extends TestCase
{
    /**
     * @param list<string> $grantedAttributes rôles / attributs accordés à l'utilisateur simulé
     *
     * @return list<string> codes des bloqueurs
     */
    private function guard(string $transition, Incident $incident, array $grantedAttributes): array
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => \in_array($attribute, $grantedAttributes, true));

        $event = new GuardEvent($incident, new Marking([$incident->getStatus()->value => 1]), new Transition($transition, $incident->getStatus()->value, 'X'));
        (new IncidentGuardListener($checker))($event);

        $codes = [];
        foreach ($event->getTransitionBlockerList() as $blocker) {
            $codes[] = $blocker->getCode();
        }

        return $codes;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supervisorTransitions(): iterable
    {
        foreach (IncidentTransition::SUPERVISOR as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('supervisorTransitions')]
    public function testSupervisorTransitionsRequireTheSupervisorRole(string $transition): void
    {
        $incident = DomainFactory::incident();

        self::assertSame([IncidentGuardListener::CODE_FORBIDDEN], $this->guard($transition, $incident, ['ROLE_AGENT', 'ROLE_TECHNICIEN']));
        self::assertSame([], $this->guard($transition, $incident, ['ROLE_SUPERVISEUR']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function technicianTransitions(): iterable
    {
        foreach ([IncidentTransition::DEMARRER, IncidentTransition::METTRE_EN_ATTENTE, IncidentTransition::REPRENDRE] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('technicianTransitions')]
    public function testTechnicianTransitionsRequireTheAssignedTechnician(string $transition): void
    {
        $incident = DomainFactory::incident(IncidentStatus::EnCours, DomainFactory::user(UserRole::Technicien));

        self::assertSame([IncidentGuardListener::CODE_FORBIDDEN], $this->guard($transition, $incident, ['ROLE_SUPERVISEUR', 'ROLE_TECHNICIEN']));
        self::assertSame([], $this->guard($transition, $incident, ['ROLE_TECHNICIEN', IncidentVoter::WORK]));
    }

    public function testResolveRequiresAnInterventionReport(): void
    {
        $tech = DomainFactory::user(UserRole::Technicien);
        $incident = DomainFactory::incident(IncidentStatus::EnCours, $tech);

        self::assertSame([IncidentGuardListener::CODE_REPORT_REQUIRED], $this->guard(IncidentTransition::RESOUDRE, $incident, [IncidentVoter::WORK]));

        $intervention = new Intervention($incident, $tech, new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('-1 hour'));
        $intervention->start(new \DateTimeImmutable('-1 hour'));
        $intervention->finish(new \DateTimeImmutable(), 'Carte remplacée.');

        self::assertSame([], $this->guard(IncidentTransition::RESOUDRE, $incident, [IncidentVoter::WORK]));
    }

    public function testForbiddenWinsOverMissingReport(): void
    {
        $incident = DomainFactory::incident(IncidentStatus::EnCours, DomainFactory::user(UserRole::Technicien));

        self::assertSame([IncidentGuardListener::CODE_FORBIDDEN], $this->guard(IncidentTransition::RESOUDRE, $incident, ['ROLE_SUPERVISEUR']));
    }

    public function testPartnerMayCancelItsOwnIncident(): void
    {
        $client = DomainFactory::apiClient();
        $incident = DomainFactory::incident(IncidentStatus::Nouveau, null, $client);

        self::assertSame([], $this->guard(IncidentTransition::ANNULER, $incident, [IncidentVoter::PARTNER_CANCEL]));
        self::assertSame([IncidentGuardListener::CODE_FORBIDDEN], $this->guard(IncidentTransition::CLORE, $incident, [IncidentVoter::PARTNER_CANCEL]));
    }

    public function testOtherSubjectsAreIgnored(): void
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $event = new GuardEvent(new \stdClass(), new Marking(['NOUVEAU' => 1]), new Transition('qualifier', 'NOUVEAU', 'QUALIFIE'));
        (new IncidentGuardListener($checker))($event);

        self::assertTrue($event->getTransitionBlockerList()->isEmpty());
    }
}
