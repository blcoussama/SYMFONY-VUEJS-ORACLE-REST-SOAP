<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardTest extends WebTestCase
{
    use WebTestHelpers;

    public function testSupervisorDashboardShowsTheIndicators(): void
    {
        $client = static::createClient();
        self::loginAs($client, AppFixtures::SUPERVISOR);
        $crawler = $client->request('GET', '/tableau-de-bord');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('[data-testid="kpi-open"]', '26');
        self::assertSelectorTextContains('[data-testid="kpi-sla"]', '%');
        self::assertCount(5, $crawler->filter('[data-testid="top-sites"] tbody tr'));
        self::assertSelectorTextContains('[data-testid="top-sites"] tbody tr:first-child', 'TNG-0042');
        self::assertCount(30, $crawler->filter('.trend-day'));
        self::assertSelectorTextContains('.sql-block', 'FROM nop_incident i');
    }

    public function testDashboardLinksOpenTheFilteredList(): void
    {
        $client = static::createClient();
        self::loginAs($client, AppFixtures::SUPERVISOR);
        $crawler = $client->request('GET', '/tableau-de-bord');

        $client->click($crawler->filter('a[href="/incidents?breached=1"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#filter-breached:checked');
    }
}
