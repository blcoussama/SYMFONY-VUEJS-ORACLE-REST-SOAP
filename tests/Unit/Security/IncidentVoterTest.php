<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Incident;
use App\Enum\IncidentStatus;
use App\Enum\UserRole;
use App\Security\Voter\IncidentVoter;
use App\Tests\Support\DomainFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\UserInterface;

final class IncidentVoterTest extends TestCase
{
    private IncidentVoter $voter;

    protected function setUp(): void
    {
        $hierarchy = new RoleHierarchy([
            'ROLE_TECHNICIEN' => ['ROLE_AGENT'],
            'ROLE_SUPERVISEUR' => ['ROLE_TECHNICIEN'],
            'ROLE_ADMIN' => ['ROLE_SUPERVISEUR'],
        ]);
        $this->voter = new IncidentVoter(new AccessDecisionManager([new RoleHierarchyVoter($hierarchy)]));
    }

    private function vote(UserInterface $principal, string $attribute, Incident $incident): int
    {
        return $this->voter->vote(new UsernamePasswordToken($principal, 'main', $principal->getRoles()), $incident, [$attribute]);
    }

    /**
     * @return iterable<string, array{UserRole, string, IncidentStatus, int}>
     */
    public static function roleMatrix(): iterable
    {
        $granted = VoterInterface::ACCESS_GRANTED;
        $denied = VoterInterface::ACCESS_DENIED;

        yield 'agent voit' => [UserRole::Agent, IncidentVoter::VIEW, IncidentStatus::Nouveau, $granted];
        yield 'agent commente' => [UserRole::Agent, IncidentVoter::COMMENT, IncidentStatus::EnCours, $granted];
        yield 'agent n’assigne pas' => [UserRole::Agent, IncidentVoter::ASSIGN, IncidentStatus::Qualifie, $denied];
        yield 'technicien n’assigne pas' => [UserRole::Technicien, IncidentVoter::ASSIGN, IncidentStatus::Qualifie, $denied];
        yield 'superviseur assigne un qualifié' => [UserRole::Superviseur, IncidentVoter::ASSIGN, IncidentStatus::Qualifie, $granted];
        yield 'superviseur réassigne un en cours' => [UserRole::Superviseur, IncidentVoter::ASSIGN, IncidentStatus::EnCours, $granted];
        yield 'pas d’assignation avant qualification' => [UserRole::Superviseur, IncidentVoter::ASSIGN, IncidentStatus::Nouveau, $denied];
        yield 'pas d’assignation d’un clos' => [UserRole::Admin, IncidentVoter::ASSIGN, IncidentStatus::Clos, $denied];
        yield 'admin priorise (hiérarchie)' => [UserRole::Admin, IncidentVoter::PRIORITIZE, IncidentStatus::Nouveau, $granted];
        yield 'pas de priorité sur un résolu' => [UserRole::Superviseur, IncidentVoter::PRIORITIZE, IncidentStatus::Resolu, $denied];
    }

    #[DataProvider('roleMatrix')]
    public function testRoleMatrix(UserRole $role, string $attribute, IncidentStatus $status, int $expected): void
    {
        self::assertSame($expected, $this->vote(DomainFactory::user($role), $attribute, DomainFactory::incident($status)));
    }

    public function testOnlyTheAssignedTechnicianCanWork(): void
    {
        $assigned = DomainFactory::user(UserRole::Technicien);
        $other = DomainFactory::user(UserRole::Technicien);
        $supervisor = DomainFactory::user(UserRole::Superviseur);
        $incident = DomainFactory::incident(IncidentStatus::EnCours, $assigned);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($assigned, IncidentVoter::WORK, $incident));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($other, IncidentVoter::WORK, $incident));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($supervisor, IncidentVoter::WORK, $incident));
    }

    public function testDisabledUserIsDenied(): void
    {
        $tech = DomainFactory::user(UserRole::Technicien, active: false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($tech, IncidentVoter::VIEW, DomainFactory::incident(IncidentStatus::EnCours, $tech)));
    }

    public function testPartnerCanViewButNotComment(): void
    {
        $client = DomainFactory::apiClient();
        $incident = DomainFactory::incident();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($client, IncidentVoter::VIEW, $incident));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($client, IncidentVoter::COMMENT, $incident));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($client, IncidentVoter::ASSIGN, $incident));
    }

    public function testPartnerCanCancelOnlyItsOwnUnassignedIncident(): void
    {
        $owner = DomainFactory::apiClient();
        $other = DomainFactory::apiClient();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($owner, IncidentVoter::PARTNER_CANCEL, DomainFactory::incident(IncidentStatus::Nouveau, null, $owner)));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($other, IncidentVoter::PARTNER_CANCEL, DomainFactory::incident(IncidentStatus::Nouveau, null, $owner)));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($owner, IncidentVoter::PARTNER_CANCEL, DomainFactory::incident(IncidentStatus::Assigne, null, $owner)));
    }

    public function testUnsupportedAttributeAbstains(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(DomainFactory::user(UserRole::Admin), 'EDIT_ANYTHING', DomainFactory::incident()));
    }
}
