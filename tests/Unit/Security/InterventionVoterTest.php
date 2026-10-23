<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Intervention;
use App\Enum\IncidentStatus;
use App\Enum\UserRole;
use App\Security\Voter\InterventionVoter;
use App\Tests\Support\DomainFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class InterventionVoterTest extends TestCase
{
    private function vote(UserInterface $user, Intervention $intervention): int
    {
        return (new InterventionVoter())->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $intervention, [InterventionVoter::EDIT]);
    }

    public function testOwnerCanEditUnfinishedIntervention(): void
    {
        $tech = DomainFactory::user(UserRole::Technicien);
        $intervention = new Intervention(DomainFactory::incident(IncidentStatus::EnCours, $tech), $tech, new \DateTimeImmutable(), new \DateTimeImmutable());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($tech, $intervention));
    }

    public function testAnotherTechnicianCannotEdit(): void
    {
        $tech = DomainFactory::user(UserRole::Technicien);
        $intervention = new Intervention(DomainFactory::incident(IncidentStatus::EnCours, $tech), $tech, new \DateTimeImmutable(), new \DateTimeImmutable());

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(DomainFactory::user(UserRole::Technicien), $intervention));
    }

    public function testFinishedInterventionIsReadOnly(): void
    {
        $tech = DomainFactory::user(UserRole::Technicien);
        $intervention = new Intervention(DomainFactory::incident(IncidentStatus::EnCours, $tech), $tech, new \DateTimeImmutable('-2 hours'), new \DateTimeImmutable('-2 hours'));
        $intervention->start(new \DateTimeImmutable('-1 hour'));
        $intervention->finish(new \DateTimeImmutable(), 'Terminé.');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($tech, $intervention));
    }

    public function testReassignedIncidentLocksThePreviousTechnician(): void
    {
        $tech = DomainFactory::user(UserRole::Technicien);
        $newTech = DomainFactory::user(UserRole::Technicien);
        $incident = DomainFactory::incident(IncidentStatus::EnCours, $tech);
        $intervention = new Intervention($incident, $tech, new \DateTimeImmutable(), new \DateTimeImmutable());
        $incident->assignTo($newTech, new \DateTimeImmutable());

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($tech, $intervention));
    }
}
