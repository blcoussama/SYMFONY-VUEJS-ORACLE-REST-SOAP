<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Pages d'erreur en français (rendues hors mode debug, comme en production). */
final class ErrorPagesTest extends WebTestCase
{
    use WebTestHelpers;

    public function testNotFoundPage(): void
    {
        $client = static::createClient(['debug' => false]);
        self::loginAs($client, AppFixtures::AGENT_1);
        $client->request('GET', '/incidents/INC-2026-999999');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Page introuvable');
    }

    public function testForbiddenPage(): void
    {
        $client = static::createClient(['debug' => false]);
        self::loginAs($client, AppFixtures::AGENT_1);
        $client->request('GET', '/admin/sites');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'Accès refusé');
    }
}
