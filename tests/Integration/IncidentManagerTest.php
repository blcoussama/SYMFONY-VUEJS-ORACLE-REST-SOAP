<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\AppFixtures;
use App\Dto\IncidentDraft;
use App\Entity\IncidentEvent;
use App\Entity\User;
use App\Enum\IncidentCategory;
use App\Enum\IncidentEventType;
use App\Enum\IncidentPriority;
use App\Enum\IncidentSource;
use App\Enum\IncidentStatus;
use App\Exception\BusinessRuleException;
use App\Exception\IncidentConflictException;
use App\Exception\TransitionRefusedException;
use App\Exception\UnknownTransitionException;
use App\Repository\SiteRepository;
use App\Service\Actor;
use App\Service\IncidentManager;
use App\Service\Reference\IncidentReference;
use App\Tests\Support\FixtureLookup;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class IncidentManagerTest extends KernelTestCase
{
    use FixtureLookup;

    private IncidentManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(IncidentManager::class);
        \assert($manager instanceof IncidentManager);
        $this->manager = $manager;
    }

    private function actingAs(string $email): Actor
    {
        $user = self::user($email);
        $storage = self::getContainer()->get('security.token_storage');
        \assert($storage instanceof TokenStorageInterface);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return Actor::user($user);
    }

    /**
     * @return list<string>
     */
    private static function eventTypes(\App\Entity\Incident $incident): array
    {
        return array_values(array_map(static fn (IncidentEvent $e): string => $e->getType()->value, $incident->getEvents()->toArray()));
    }

    public function testDeclareComputesReferenceSlaAndJournal(): void
    {
        $actor = $this->actingAs(AppFixtures::AGENT_1);
        $sites = self::getContainer()->get(SiteRepository::class);
        \assert($sites instanceof SiteRepository);

        $draft = new IncidentDraft();
        $draft->title = 'Coupure fibre sur le lien de collecte';
        $draft->description = 'Perte totale du lien optique vers le NRO.';
        $draft->site = $sites->findOneActiveByCode(AppFixtures::SITE_TANGER);
        $draft->category = IncidentCategory::CoupureFibre;
        $draft->priority = IncidentPriority::P1;

        $incident = $this->manager->declare($draft, $actor);

        self::assertTrue(IncidentReference::isValid($incident->getReference()));
        self::assertSame(IncidentStatus::Nouveau, $incident->getStatus());
        self::assertSame(IncidentSource::Web, $incident->getSource());
        self::assertSame(4 * 3600, $incident->getSlaDueAt()->getTimestamp() - $incident->getCreatedAt()->getTimestamp());
        self::assertSame(['CREATION'], self::eventTypes(self::reload($incident)));
    }

    public function testFullLifecycleWritesOneEventPerTransition(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        $supervisor = $this->actingAs(AppFixtures::SUPERVISOR);
        $before = $incident->getEvents()->count();

        $this->manager->applyTransition($incident, 'qualifier', $supervisor, 'Impact confirmé', null, ['priority' => IncidentPriority::P1]);
        $this->manager->assign($incident, self::user(AppFixtures::TECHNICIAN_1), $supervisor);

        $tech = $this->actingAs(AppFixtures::TECHNICIAN_1);
        $this->manager->applyTransition($incident, 'demarrer', $tech);
        $this->manager->applyTransition($incident, 'mettre_en_attente', $tech, 'Pièce commandée');
        $this->manager->applyTransition($incident, 'reprendre', $tech);
        $this->manager->applyTransition($incident, 'resoudre', $tech, null, null, ['report' => 'Carte remplacée, service rétabli.']);
        self::assertNotNull($incident->getResolvedAt());

        $supervisor = $this->actingAs(AppFixtures::SUPERVISOR);
        $this->manager->applyTransition($incident, 'clore', $supervisor);

        $incident = self::reload($incident);
        self::assertSame(IncidentStatus::Clos, $incident->getStatus());
        self::assertNotNull($incident->getClosedAt());
        self::assertSame(IncidentPriority::P1, $incident->getPriority());
        self::assertTrue($incident->hasCompletedInterventionReport());
        self::assertSame(
            ['PRIORITE', 'TRANSITION', 'ASSIGNATION', 'TRANSITION', 'TRANSITION', 'TRANSITION', 'TRANSITION', 'TRANSITION', 'TRANSITION'],
            \array_slice(self::eventTypes($incident), $before),
        );
    }

    public function testStateConflictIsRefused(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);

        try {
            $this->manager->applyTransition($incident, 'clore', $this->actingAs(AppFixtures::SUPERVISOR));
            self::fail('Transition acceptée à tort.');
        } catch (TransitionRefusedException $e) {
            self::assertFalse($e->isForbidden());
            self::assertSame(IncidentStatus::Nouveau, $incident->getStatus());
        }
    }

    public function testAgentCannotQualify(): void
    {
        $this->expectException(TransitionRefusedException::class);

        $this->manager->applyTransition(self::incidentIn(IncidentStatus::Nouveau), 'qualifier', $this->actingAs(AppFixtures::AGENT_1));
    }

    public function testOtherTechnicianCannotStart(): void
    {
        $incident = self::incidentIn(IncidentStatus::Assigne, AppFixtures::TECHNICIAN_1);

        try {
            $this->manager->applyTransition($incident, 'demarrer', $this->actingAs(AppFixtures::TECHNICIAN_2));
            self::fail('Transition acceptée à tort.');
        } catch (TransitionRefusedException $e) {
            self::assertTrue($e->isForbidden());
        }
    }

    public function testResolveWithoutReportIsRefused(): void
    {
        $incident = self::incidentIn(IncidentStatus::EnCours, AppFixtures::TECHNICIAN_1);
        self::assertFalse($incident->hasCompletedInterventionReport());

        $this->expectException(TransitionRefusedException::class);
        $this->expectExceptionMessage('compte rendu');
        $this->manager->applyTransition($incident, 'resoudre', $this->actingAs(AppFixtures::TECHNICIAN_1));
    }

    public function testUnknownTransition(): void
    {
        $this->expectException(UnknownTransitionException::class);

        $this->manager->applyTransition(self::incidentIn(IncidentStatus::Nouveau), 'teleporter', $this->actingAs(AppFixtures::SUPERVISOR));
    }

    public function testStaleVersionIsRejected(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);

        $this->expectException(IncidentConflictException::class);
        $this->manager->applyTransition($incident, 'qualifier', $this->actingAs(AppFixtures::SUPERVISOR), null, $incident->getVersion() - 1 ?: 99);
    }

    public function testVersionIsIncrementedOnEachWrite(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        $version = $incident->getVersion();

        $this->manager->applyTransition($incident, 'qualifier', $this->actingAs(AppFixtures::SUPERVISOR), null, $version);

        self::assertSame($version + 1, self::reload($incident)->getVersion());
    }

    public function testAssignRequiresAnActiveTechnician(): void
    {
        $this->expectException(BusinessRuleException::class);

        $this->manager->assign(self::incidentIn(IncidentStatus::Qualifie), self::user(AppFixtures::AGENT_2), $this->actingAs(AppFixtures::SUPERVISOR));
    }

    public function testReassignmentRecordsAnAssignationEvent(): void
    {
        $incident = self::incidentIn(IncidentStatus::EnCours, AppFixtures::TECHNICIAN_1);
        $this->manager->assign($incident, self::user(AppFixtures::TECHNICIAN_2), $this->actingAs(AppFixtures::SUPERVISOR), 'Renfort');

        $incident = self::reload($incident);
        $last = $incident->getEvents()->last();
        self::assertInstanceOf(IncidentEvent::class, $last);
        self::assertSame(IncidentEventType::Assignation, $last->getType());
        self::assertStringContainsString('Réassigné de Karim Tazi à Imane Chraibi', (string) $last->getComment());
        self::assertSame(IncidentStatus::EnCours, $incident->getStatus());
        self::assertInstanceOf(User::class, $incident->getAssignee());
    }

    public function testChangingPriorityRecomputesTheDeadline(): void
    {
        $incident = self::incidentIn(IncidentStatus::Qualifie);
        $this->manager->changePriority($incident, IncidentPriority::P4, $this->actingAs(AppFixtures::SUPERVISOR));

        $incident = self::reload($incident);
        self::assertSame(72 * 3600, $incident->getSlaDueAt()->getTimestamp() - $incident->getCreatedAt()->getTimestamp());
    }

    public function testReopenClearsResolutionAndReturnsToTechnician(): void
    {
        $incident = self::incidentIn(IncidentStatus::Resolu);
        $assignee = $incident->getAssignee();
        $this->manager->applyTransition($incident, 'rouvrir', $this->actingAs(AppFixtures::SUPERVISOR), 'Le défaut est réapparu');

        $incident = self::reload($incident);
        self::assertSame(IncidentStatus::Assigne, $incident->getStatus());
        self::assertNull($incident->getResolvedAt());
        self::assertSame($assignee?->getId(), $incident->getAssignee()?->getId());
    }

    public function testCommentIsJournaled(): void
    {
        $incident = self::incidentIn(IncidentStatus::EnCours);
        $event = $this->manager->comment($incident, '  Client prévenu.  ', $this->actingAs(AppFixtures::AGENT_2));

        self::assertSame('Client prévenu.', $event->getComment());
        self::assertSame('Salma Berrada', $event->getAuthorLabel());
    }
}
