<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\DataFixtures\AppFixtures;
use App\Entity\Incident;
use App\Enum\IncidentStatus;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** POST /api/v1/incidents/{reference}/transitions/{transition} : 200, 403, 404, 409, 422. */
final class TransitionApiTest extends WebTestCase
{
    use WebTestHelpers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * Appel avec la session d'un utilisateur (comme le Kanban) : jeton CSRF en en-tête.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function asUser(string $email, Incident $incident, string $transition, array $body = [], array $headers = []): void
    {
        self::loginAs($this->client, $email);
        $token = self::attributeFrom($this->client, '/kanban', '#kanban-app', 'data-csrf-token');
        self::jsonRequest($this->client, 'POST', '/api/v1/incidents/'.$incident->getReference().'/transitions/'.$transition, $body, $headers + ['X-CSRF-Token' => $token]);
    }

    public function testPartnerCannotQualify(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        self::partnerRequest($this->client, 'POST', '/api/v1/incidents/'.$incident->getReference().'/transitions/qualifier', []);

        self::assertResponseStatusCodeSame(403);
        $problem = self::json($this->client);
        self::assertSame('qualifier', $problem['transition']);
        self::assertSame('netops.forbidden', $problem['blockers'][0]['code']);
    }

    public function testPartnerCancelsItsOwnIncident(): void
    {
        self::partnerRequest($this->client, 'POST', '/api/v1/incidents', [
            'title' => 'Fausse alarme radio', 'description' => 'Alarme levée par erreur par l’OSS.',
            'siteCode' => 'RBT-0058', 'category' => 'RADIO',
        ]);
        $reference = self::json($this->client)['reference'];

        self::partnerRequest($this->client, 'POST', "/api/v1/incidents/$reference/transitions/annuler", ['comment' => 'Fausse alarme']);

        self::assertResponseIsSuccessful();
        $body = self::json($this->client);
        self::assertSame('ANNULE', $body['status']);
        self::assertSame('NOT_APPLICABLE', $body['sla']['state']);
        self::assertSame('Fausse alarme', end($body['events'])['comment']);
    }

    public function testPartnerCannotCancelSomeoneElsesIncident(): void
    {
        $incident = self::em()->getRepository(Incident::class)->findOneBy(['status' => IncidentStatus::Nouveau, 'apiClient' => null]);
        self::assertNotNull($incident);
        self::partnerRequest($this->client, 'POST', '/api/v1/incidents/'.$incident->getReference().'/transitions/annuler', []);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSupervisorQualifiesWithPriority(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        $this->asUser(AppFixtures::SUPERVISOR, $incident, 'qualifier', ['priority' => 'P1', 'comment' => 'Client VIP']);

        self::assertResponseIsSuccessful();
        $body = self::json($this->client);
        self::assertSame('QUALIFIE', $body['status']);
        self::assertSame('P1', $body['priority']);
        self::assertSame($incident->getVersion() + 1, $body['version']);
    }

    public function testUnknownTransitionIs404(): void
    {
        $this->asUser(AppFixtures::SUPERVISOR, self::incidentIn(IncidentStatus::Nouveau), 'teleporter');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Transition inconnue : « teleporter ».', self::json($this->client)['detail']);
    }

    public function testTransitionFromTheWrongStatusIs409(): void
    {
        $this->asUser(AppFixtures::SUPERVISOR, self::incidentIn(IncidentStatus::Nouveau), 'clore');

        self::assertResponseStatusCodeSame(409);
        $problem = self::json($this->client);
        self::assertStringEndsWith('/transition-refused', $problem['type']);
        self::assertSame('Cette action n’est pas possible dans le statut actuel de l’incident.', $problem['detail']);
    }

    public function testStaleIfMatchIs409VersionConflict(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        $this->asUser(AppFixtures::SUPERVISOR, $incident, 'qualifier', [], ['If-Match' => '"'.($incident->getVersion() + 3).'"']);

        self::assertResponseStatusCodeSame(409);
        self::assertStringEndsWith('/version-conflict', self::json($this->client)['type']);
        self::assertSame(IncidentStatus::Nouveau, self::reload($incident)->getStatus());
    }

    public function testResolveNeedsAReportThenSucceeds(): void
    {
        $incident = self::incidentIn(IncidentStatus::EnCours, AppFixtures::TECHNICIAN_2);

        $this->asUser(AppFixtures::TECHNICIAN_2, $incident, 'resoudre');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('netops.report_required', self::json($this->client)['blockers'][0]['code']);

        $this->asUser(AppFixtures::TECHNICIAN_2, $incident, 'resoudre', ['report' => 'Batterie remplacée, alarme levée.']);
        self::assertResponseIsSuccessful();
        $body = self::json($this->client);
        self::assertSame('RESOLU', $body['status']);
        self::assertNotNull($body['resolvedAt']);
        self::assertSame('Batterie remplacée, alarme levée.', end($body['interventions'])['report']);
    }

    public function testNonAssignedTechnicianIs403(): void
    {
        $this->asUser(AppFixtures::TECHNICIAN_2, self::incidentIn(IncidentStatus::Assigne, AppFixtures::TECHNICIAN_1), 'demarrer');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Action réservée au technicien assigné à cet incident.', self::json($this->client)['detail']);
    }

    public function testAssignNeedsAKnownTechnician(): void
    {
        $incident = self::incidentIn(IncidentStatus::Qualifie);

        $this->asUser(AppFixtures::SUPERVISOR, $incident, 'assigner', ['assignee' => AppFixtures::AGENT_1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('assignee', self::json($this->client)['violations'][0]['field']);

        $this->asUser(AppFixtures::SUPERVISOR, $incident, 'assigner');
        self::assertResponseStatusCodeSame(422);

        $this->asUser(AppFixtures::SUPERVISOR, $incident, 'assigner', ['assignee' => AppFixtures::TECHNICIAN_1]);
        self::assertResponseIsSuccessful();
        self::assertSame(AppFixtures::TECHNICIAN_1, self::json($this->client)['assignee']['email']);
    }

    public function testInvalidBodyIs422(): void
    {
        $this->asUser(AppFixtures::SUPERVISOR, self::incidentIn(IncidentStatus::Nouveau), 'qualifier', ['priority' => 'URGENT', 'comment' => str_repeat('x', 1001)]);

        self::assertResponseStatusCodeSame(422);
        $fields = array_column(self::json($this->client)['violations'], 'field');
        self::assertContains('priority', $fields);
        self::assertContains('comment', $fields);
    }
}
