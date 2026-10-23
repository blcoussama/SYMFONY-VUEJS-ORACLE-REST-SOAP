<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class IncidentListTest extends WebTestCase
{
    use WebTestHelpers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::loginAs($this->client, AppFixtures::AGENT_1);
    }

    /**
     * @param array<string, string> $query
     */
    private function list(array $query = [], bool $ajax = false): Crawler
    {
        return $this->client->request('GET', '/incidents', $query, [], $ajax ? ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : []);
    }

    /**
     * @return list<string>
     */
    private static function column(Crawler $crawler, int $index): array
    {
        return $crawler->filter('#incident-results tbody tr, [data-total] tbody tr')->each(static fn (Crawler $tr): string => trim($tr->filter('td')->eq($index)->text('')));
    }

    public function testListShowsAllIncidentsPaginated(): void
    {
        $crawler = $this->list();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#incident-results .card-header', '40 incidents');
        self::assertCount(15, $crawler->filter('#incident-results tbody tr'));
        self::assertSelectorExists('form#incident-filters[role="search"]');
    }

    public function testFilterByStatus(): void
    {
        $crawler = $this->list(['status' => 'NOUVEAU']);

        self::assertSame(array_fill(0, 6, 'Nouveau'), self::column($crawler, 4));
    }

    public function testOpenStatusesFilter(): void
    {
        $crawler = $this->list(['status' => 'OUVERTS']);

        self::assertSelectorTextContains('#incident-results .card-header', '26 incidents');
        self::assertNotContains('Clos', self::column($crawler, 4));
    }

    public function testFilterByPriorityAndSearch(): void
    {
        $crawler = $this->list(['priority' => 'P1']);
        self::assertNotEmpty(self::column($crawler, 3));
        self::assertSame(['P1'], array_values(array_unique(self::column($crawler, 3))));

        $crawler = $this->list(['q' => 'INC-2026-000007']);
        self::assertSame(['INC-2026-000007'], self::column($crawler, 0));
    }

    public function testBreachedFilterOnlyShowsOverdueOpenIncidents(): void
    {
        $crawler = $this->list(['breached' => '1']);

        $sla = self::column($crawler, 6);
        self::assertNotEmpty($sla);
        foreach ($sla as $label) {
            self::assertStringStartsWith('Dépassé de', $label);
        }
    }

    public function testRegionAndSiteFilters(): void
    {
        $crawler = $this->list(['region' => 'Casablanca-Settat']);
        foreach (self::column($crawler, 2) as $code) {
            self::assertMatchesRegularExpression('/^(CAS|ELJ)-/', $code);
        }

        $siteId = (string) $crawler->filter('#filter-site option')->eq(1)->attr('value');
        $crawler = $this->list(['region' => 'Casablanca-Settat', 'site' => $siteId]);
        self::assertCount(1, array_unique(self::column($crawler, 2)));
    }

    public function testSortByPriorityAscending(): void
    {
        $crawler = $this->list(['sort' => 'priority', 'dir' => 'asc']);

        self::assertSame('P1', self::column($crawler, 3)[0]);
        self::assertSelectorExists('a[aria-sort="ascending"]');
    }

    public function testSecondPage(): void
    {
        $crawler = $this->list(['page' => '3']);

        self::assertCount(10, $crawler->filter('#incident-results tbody tr'));
        self::assertSelectorTextContains('#incident-results .card-header', 'Page 3 / 3');
    }

    public function testAjaxRequestReturnsOnlyTheResultsFragment(): void
    {
        $crawler = $this->list(['status' => 'CLOS'], ajax: true);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<html', $html);
        self::assertStringNotContainsString('incident-filters', $html);
        self::assertSame('6', $crawler->filter('[data-total]')->attr('data-total'));
    }

    public function testUnknownFilterValuesAreIgnored(): void
    {
        $this->list(['status' => 'INCONNU', 'priority' => 'P9', 'sort' => 'password', 'page' => '-4']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#incident-results .card-header', '40 incidents');
    }

    public function testSitesLookupByRegion(): void
    {
        $this->client->request('GET', '/ajax/sites', ['region' => 'L’Oriental']);

        self::assertResponseIsSuccessful();
        $sites = self::json($this->client);
        self::assertSame(['NAD-0006', 'OUJ-0021'], array_column($sites, 'code'));

        $this->client->request('GET', '/ajax/sites');
        self::assertCount(20, self::json($this->client), 'Le site désactivé n’est pas proposé');
    }
}
