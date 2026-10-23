<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Tests\Support\WebTestHelpers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Matrice des droits par rôle (SPEC §1). */
final class AccessControlTest extends WebTestCase
{
    use WebTestHelpers;

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function pages(): iterable
    {
        yield 'agent : liste' => [AppFixtures::AGENT_1, '/incidents', 200];
        yield 'agent : déclarer' => [AppFixtures::AGENT_1, '/incidents/declarer', 200];
        yield 'agent : kanban' => [AppFixtures::AGENT_1, '/kanban', 200];
        yield 'agent : mes interventions' => [AppFixtures::AGENT_1, '/mes-interventions', 403];
        yield 'agent : tableau de bord' => [AppFixtures::AGENT_1, '/tableau-de-bord', 403];
        yield 'agent : administration' => [AppFixtures::AGENT_1, '/admin/sites', 403];
        yield 'technicien : mes interventions' => [AppFixtures::TECHNICIAN_1, '/mes-interventions', 200];
        yield 'technicien : tableau de bord' => [AppFixtures::TECHNICIAN_1, '/tableau-de-bord', 403];
        yield 'superviseur : tableau de bord' => [AppFixtures::SUPERVISOR, '/tableau-de-bord', 200];
        yield 'superviseur : mes interventions (hérité)' => [AppFixtures::SUPERVISOR, '/mes-interventions', 200];
        yield 'superviseur : utilisateurs' => [AppFixtures::SUPERVISOR, '/admin/utilisateurs', 403];
        yield 'superviseur : clés API' => [AppFixtures::SUPERVISOR, '/admin/cles-api', 403];
        yield 'admin : sites' => [AppFixtures::ADMIN, '/admin/sites', 200];
        yield 'admin : utilisateurs' => [AppFixtures::ADMIN, '/admin/utilisateurs', 200];
        yield 'admin : clés API' => [AppFixtures::ADMIN, '/admin/cles-api', 200];
        yield 'admin : tableau de bord (hérité)' => [AppFixtures::ADMIN, '/tableau-de-bord', 200];
    }

    #[DataProvider('pages')]
    public function testPageAccess(string $email, string $uri, int $status): void
    {
        $client = static::createClient();
        self::loginAs($client, $email);
        $client->request('GET', $uri);

        self::assertResponseStatusCodeSame($status);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function homePages(): iterable
    {
        yield 'agent' => [AppFixtures::AGENT_1, '/incidents'];
        yield 'technicien' => [AppFixtures::TECHNICIAN_1, '/mes-interventions'];
        yield 'superviseur' => [AppFixtures::SUPERVISOR, '/tableau-de-bord'];
    }

    #[DataProvider('homePages')]
    public function testHomeDependsOnRole(string $email, string $target): void
    {
        $client = static::createClient();
        self::loginAs($client, $email);
        $client->request('GET', '/');

        self::assertResponseRedirects($target);
    }

    public function testNavigationDependsOnRole(): void
    {
        $client = static::createClient();
        self::loginAs($client, AppFixtures::AGENT_1);
        $client->request('GET', '/incidents');

        self::assertSelectorNotExists('a[href="/tableau-de-bord"]');
        self::assertSelectorNotExists('a[href="/admin/sites"]');

        self::loginAs($client, AppFixtures::ADMIN);
        $client->request('GET', '/incidents');
        self::assertSelectorExists('a[href="/tableau-de-bord"]');
        self::assertSelectorExists('a[href="/admin/sites"]');
    }
}
