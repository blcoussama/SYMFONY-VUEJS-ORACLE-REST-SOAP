<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Enum\IncidentStatus;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le Kanban (Vue.js) appelle l'API REST avec la session : on vérifie la page de configuration
 * et le contrat des appels faits par le glisser-déposer.
 */
final class KanbanTest extends WebTestCase
{
    use WebTestHelpers;

    public function testPageExposesTheWorkflowAndACsrfToken(): void
    {
        $client = static::createClient();
        self::loginAs($client, AppFixtures::SUPERVISOR);
        $crawler = $client->request('GET', '/kanban');

        self::assertResponseIsSuccessful();
        $root = $crawler->filter('#kanban-app');
        $config = json_decode((string) $root->attr('data-config'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($config);
        self::assertSame('/api/v1/incidents', $config['apiBase']);
        self::assertCount(8, $config['columns']);
        self::assertContains(['name' => 'demarrer', 'from' => 'ASSIGNE', 'to' => 'EN_COURS', 'label' => 'Démarrer'], $config['transitions']);
        self::assertCount(2, $config['technicians']);
        self::assertNotEmpty($root->attr('data-csrf-token'));
        self::assertStringContainsString('kanban', (string) $crawler->filter('script[type="importmap"]')->text());
    }

    public function testDragAndDropCallSucceedsWithSessionAndCsrfHeader(): void
    {
        $client = static::createClient();
        self::loginAs($client, AppFixtures::SUPERVISOR);
        $token = self::attributeFrom($client, '/kanban', '#kanban-app', 'data-csrf-token');
        $incident = self::incidentIn(IncidentStatus::Nouveau);

        self::jsonRequest($client, 'POST', '/api/v1/incidents/'.$incident->getReference().'/transitions/qualifier', [], [
            'X-CSRF-Token' => $token,
            'If-Match' => '"'.$incident->getVersion().'"',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('QUALIFIE', self::json($client)['status']);
    }

    public function testSessionCallWithoutCsrfHeaderIsRefused(): void
    {
        $client = static::createClient();
        self::loginAs($client, AppFixtures::SUPERVISOR);
        $incident = self::incidentIn(IncidentStatus::Nouveau);

        self::jsonRequest($client, 'POST', '/api/v1/incidents/'.$incident->getReference().'/transitions/qualifier', []);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertStringContainsString('CSRF', (string) self::json($client)['detail']);
    }

    public function testRefusedDropReturnsAProblemTheCardCanDisplay(): void
    {
        $client = static::createClient();
        self::loginAs($client, AppFixtures::AGENT_1);
        $token = self::attributeFrom($client, '/kanban', '#kanban-app', 'data-csrf-token');
        $incident = self::incidentIn(IncidentStatus::Nouveau);

        self::jsonRequest($client, 'POST', '/api/v1/incidents/'.$incident->getReference().'/transitions/qualifier', [], ['X-CSRF-Token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Action réservée au superviseur.', self::json($client)['detail']);
    }

    public function testAllowedTransitionsDependOnTheUser(): void
    {
        $client = static::createClient();
        $incident = self::incidentIn(IncidentStatus::Assigne, AppFixtures::TECHNICIAN_1);

        self::loginAs($client, AppFixtures::TECHNICIAN_1);
        $client->request('GET', '/api/v1/incidents/'.$incident->getReference());
        self::assertSame(['demarrer'], self::json($client)['allowedTransitions']);

        self::loginAs($client, AppFixtures::TECHNICIAN_2);
        $client->request('GET', '/api/v1/incidents/'.$incident->getReference());
        self::assertSame([], self::json($client)['allowedTransitions']);
    }
}
