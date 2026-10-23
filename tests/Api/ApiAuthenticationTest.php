<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\DataFixtures\AppFixtures;
use App\Entity\ApiClient;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiAuthenticationTest extends WebTestCase
{
    use WebTestHelpers;

    public function testHealthIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/health');

        self::assertResponseIsSuccessful();
        $body = self::json($client);
        self::assertSame('ok', $body['status']);
        self::assertSame('ok', $body['database']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $body['time']);
    }

    public function testMissingCredentialsGive401ProblemJson(): void
    {
        $client = static::createClient();
        self::jsonRequest($client, 'GET', '/api/v1/incidents');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHasHeader('WWW-Authenticate');
        $problem = self::json($client);
        self::assertSame(401, $problem['status']);
        self::assertSame('Authentification requise', $problem['title']);
        self::assertArrayHasKey('type', $problem);
    }

    public function testInvalidKeyGives401(): void
    {
        $client = static::createClient();
        self::partnerRequest($client, 'GET', '/api/v1/incidents', null, 'nop_cle_inventee');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('Clé d’API invalide.', self::json($client)['detail']);
    }

    public function testRevokedKeyGives401(): void
    {
        $client = static::createClient();
        self::partnerRequest($client, 'GET', '/api/v1/incidents', null, AppFixtures::REVOKED_API_KEY);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('Clé d’API révoquée.', self::json($client)['detail']);
    }

    public function testValidKeyIsStatelessAndRecordsLastUse(): void
    {
        $client = static::createClient();
        $before = self::em()->getRepository(ApiClient::class)->findOneBy(['name' => 'OSS Supervision Nord (démo)'])?->getLastUsedAt();

        self::partnerRequest($client, 'GET', '/api/v1/incidents');

        self::assertResponseIsSuccessful();
        self::assertResponseNotHasCookie('PHPSESSID');
        self::assertEmpty($client->getResponse()->headers->getCookies(), 'Aucune session pour un appel par clé');
        self::em()->clear();
        $after = self::em()->getRepository(ApiClient::class)->findOneBy(['name' => 'OSS Supervision Nord (démo)'])?->getLastUsedAt();
        self::assertNotNull($after);
        self::assertGreaterThan($before, $after);
    }

    public function testSessionUserCanReadTheApi(): void
    {
        $client = static::createClient();
        self::loginAs($client, AppFixtures::AGENT_1);
        self::jsonRequest($client, 'GET', '/api/v1/incidents?limit=1');

        self::assertResponseIsSuccessful();
        self::assertSame(40, self::json($client)['total']);
    }

    public function testUnknownApiRouteIsAProblem404(): void
    {
        $client = static::createClient();
        self::partnerRequest($client, 'GET', '/api/v1/inconnu');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('Ressource introuvable.', self::json($client)['detail']);
    }

    public function testWrongMethodIs405(): void
    {
        $client = static::createClient();
        self::partnerRequest($client, 'DELETE', '/api/v1/sites');

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }
}
