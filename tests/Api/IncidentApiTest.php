<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\DataFixtures\AppFixtures;
use App\Enum\IncidentStatus;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IncidentApiTest extends WebTestCase
{
    use WebTestHelpers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function validPayload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Perte de lien fibre détectée par l’OSS',
            'description' => 'Alarme LOS sur le port 1/1/3 du NRO.',
            'siteCode' => 'TNG-0107',
            'category' => 'COUPURE_FIBRE',
            'priority' => 'P2',
        ];
    }

    public function testListIsPaginated(): void
    {
        self::partnerRequest($this->client, 'GET', '/api/v1/incidents');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $page = self::json($this->client);
        self::assertSame(40, $page['total']);
        self::assertSame(20, $page['limit']);
        self::assertSame(2, $page['pages']);
        self::assertCount(20, $page['items']);
        self::assertSame(['reference', 'title', 'description', 'status', 'statusLabel', 'priority', 'category', 'source', 'site', 'reporter', 'assignee', 'sla', 'createdAt', 'updatedAt', 'resolvedAt', 'closedAt', 'version', 'allowedTransitions', '_links'], array_keys($page['items'][0]));
    }

    public function testFiltersByStatusPriorityAndSite(): void
    {
        self::partnerRequest($this->client, 'GET', '/api/v1/incidents?status=NOUVEAU,QUALIFIE&limit=100');
        $statuses = array_unique(array_column(self::json($this->client)['items'], 'status'));
        sort($statuses);
        self::assertSame(['NOUVEAU', 'QUALIFIE'], $statuses);

        self::partnerRequest($this->client, 'GET', '/api/v1/incidents?priority=P1&site=TNG-0042&limit=100');
        foreach (self::json($this->client)['items'] as $item) {
            self::assertSame('P1', $item['priority']);
            self::assertSame('TNG-0042', $item['site']['code']);
        }
    }

    public function testLimitAbove100Is422(): void
    {
        self::partnerRequest($this->client, 'GET', '/api/v1/incidents?limit=101');

        self::assertResponseStatusCodeSame(422);
        $problem = self::json($this->client);
        self::assertSame('limit', $problem['violations'][0]['field']);
    }

    public function testUnknownStatusIs422(): void
    {
        self::partnerRequest($this->client, 'GET', '/api/v1/incidents?status=NOUVEAU,FERME');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('FERME', self::json($this->client)['violations'][0]['message']);
    }

    public function testShowReturnsTheDetailWithETag(): void
    {
        $incident = self::incidentIn(IncidentStatus::Resolu);
        self::partnerRequest($this->client, 'GET', '/api/v1/incidents/'.$incident->getReference());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('ETag', '"'.$incident->getVersion().'"');
        $body = self::json($this->client);
        self::assertSame($incident->getReference(), $body['reference']);
        self::assertContains($body['sla']['state'], ['MET', 'MISSED']);
        self::assertNotEmpty($body['events']);
        self::assertNotEmpty($body['interventions'][0]['report']);
        self::assertSame('CREATION', $body['events'][0]['type']);
    }

    public function testUnknownIncidentIs404(): void
    {
        self::partnerRequest($this->client, 'GET', '/api/v1/incidents/INC-2026-999999');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('Incident « INC-2026-999999 » introuvable.', self::json($this->client)['detail']);
    }

    public function testPartnerCreatesAnIncident(): void
    {
        self::partnerRequest($this->client, 'POST', '/api/v1/incidents', self::validPayload());

        self::assertResponseStatusCodeSame(201);
        $body = self::json($this->client);
        self::assertResponseHeaderSame('Location', '/api/v1/incidents/'.$body['reference']);
        self::assertMatchesRegularExpression('/^INC-\d{4}-\d{6}$/', $body['reference']);
        self::assertSame('NOUVEAU', $body['status']);
        self::assertSame('API_REST', $body['source']);
        self::assertSame('Partenaire : OSS Supervision Nord (démo)', $body['reporter']);
        self::assertSame(['annuler'], $body['allowedTransitions'], 'Le partenaire peut annuler son propre incident');
    }

    public function testPriorityDefaultsToP3(): void
    {
        $payload = self::validPayload();
        unset($payload['priority']);
        self::partnerRequest($this->client, 'POST', '/api/v1/incidents', $payload);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('P3', self::json($this->client)['priority']);
    }

    public function testValidationErrorsAreReportedPerField(): void
    {
        self::partnerRequest($this->client, 'POST', '/api/v1/incidents', ['title' => 'Ko', 'category' => 'INCENDIE', 'priority' => 'P0']);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $fields = array_column(self::json($this->client)['violations'], 'field');
        foreach (['title', 'description', 'siteCode', 'category', 'priority'] as $field) {
            self::assertContains($field, $fields);
        }
    }

    public function testUnknownOrInactiveSiteIs422(): void
    {
        foreach (['XXX-0000', AppFixtures::SITE_INACTIVE] as $code) {
            self::partnerRequest($this->client, 'POST', '/api/v1/incidents', self::validPayload(['siteCode' => $code]));

            self::assertResponseStatusCodeSame(422);
            self::assertSame('siteCode', self::json($this->client)['violations'][0]['field']);
        }
    }

    public function testMalformedJsonIs400(): void
    {
        $this->client->request('POST', '/api/v1/incidents', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_KEY' => AppFixtures::DEMO_API_KEY], '{"title": ');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testUnsupportedContentTypeIs415(): void
    {
        $this->client->request('POST', '/api/v1/incidents', [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_X_API_KEY' => AppFixtures::DEMO_API_KEY], 'titre=Panne');

        self::assertResponseStatusCodeSame(415);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testWrongFieldTypeIs422(): void
    {
        self::partnerRequest($this->client, 'POST', '/api/v1/incidents', self::validPayload(['title' => ['pas', 'une', 'chaîne']]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSitesEndpoint(): void
    {
        self::partnerRequest($this->client, 'GET', '/api/v1/sites');
        $body = self::json($this->client);
        self::assertSame(20, $body['total']);
        self::assertNotContains(AppFixtures::SITE_INACTIVE, array_column($body['items'], 'code'));

        self::partnerRequest($this->client, 'GET', '/api/v1/sites?region='.rawurlencode('Marrakech-Safi'));
        self::assertSame(['MRK-0027', 'SAF-0004'], array_column(self::json($this->client)['items'], 'code'));
    }
}
