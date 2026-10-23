<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\Site;
use App\Repository\IncidentRepository;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IncidentDeclareTest extends WebTestCase
{
    use WebTestHelpers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::loginAs($this->client, AppFixtures::AGENT_1);
    }

    private static function siteId(string $code): string
    {
        $site = self::em()->getRepository(Site::class)->findOneBy(['code' => $code]);
        self::assertNotNull($site);

        return (string) $site->getId();
    }

    public function testFormIsAccessibleAndLabelled(): void
    {
        $this->client->request('GET', '/incidents/declarer');

        self::assertResponseIsSuccessful();
        foreach (['incident_title', 'incident_region', 'incident_site', 'incident_category', 'incident_priority', 'incident_description'] as $id) {
            self::assertSelectorExists("label[for=\"$id\"]");
        }
        self::assertSelectorExists('input[name="incident[_token]"]');
        self::assertSelectorExists('#incident_region[data-sites-url="/ajax/sites"]');
    }

    public function testValidDeclarationCreatesTheIncident(): void
    {
        $crawler = $this->client->request('GET', '/incidents/declarer');
        $this->client->submit($crawler->selectButton('Déclarer')->form([
            'incident[title]' => 'Perte de synchronisation radio',
            'incident[site]' => self::siteId(AppFixtures::SITE_TANGER),
            'incident[category]' => 'RADIO',
            'incident[priority]' => 'P2',
            'incident[description]' => 'Secteur 3 en alarme depuis 10 minutes.',
        ]));

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'déclaré');
        self::assertSelectorTextContains('h1', 'INC-');
        self::assertSelectorTextContains('.lead', 'Perte de synchronisation radio');
        self::assertSame('Reste 8 h', trim($crawler->filter('[data-testid="sla-badge"]')->text()));
    }

    public function testInvalidDeclarationShowsFieldErrors(): void
    {
        $crawler = $this->client->request('GET', '/incidents/declarer');
        $this->client->submit($crawler->selectButton('Déclarer')->form([
            'incident[title]' => '',
            'incident[description]' => 'court',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#incident_title + .invalid-feedback, .invalid-feedback', 'Le titre est obligatoire');
        self::assertSelectorTextContains('body', 'Choisissez un site');
        self::assertSelectorTextContains('body', 'Choisissez une catégorie');
    }

    public function testInactiveSiteCannotBeChosen(): void
    {
        $crawler = $this->client->request('GET', '/incidents/declarer');
        $form = $crawler->selectButton('Déclarer')->form();
        $values = $form->getPhpValues();
        $values['incident'] = ['title' => 'Site démonté', 'site' => self::siteId(AppFixtures::SITE_INACTIVE), 'category' => 'AUTRE', 'priority' => 'P4', 'description' => 'Tentative sur un site inactif.', '_token' => $values['incident']['_token']];
        $this->client->request('POST', '/incidents/declarer', $values);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCsrfTokenIsRequired(): void
    {
        $before = self::getContainer()->get(IncidentRepository::class)->count([]);
        $this->client->request('POST', '/incidents/declarer', ['incident' => [
            'title' => 'Sans jeton CSRF', 'site' => self::siteId(AppFixtures::SITE_TANGER), 'category' => 'AUTRE',
            'priority' => 'P4', 'description' => 'Formulaire forgé sans jeton.', '_token' => 'invalide',
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'CSRF');
        self::assertSame($before, self::getContainer()->get(IncidentRepository::class)->count([]));
    }
}
