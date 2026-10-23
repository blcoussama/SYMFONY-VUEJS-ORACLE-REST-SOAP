<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\ApiClient;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminTest extends WebTestCase
{
    use WebTestHelpers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::loginAs($this->client, AppFixtures::ADMIN);
    }

    public function testCreateASite(): void
    {
        $crawler = $this->client->request('GET', '/admin/sites/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'site_form[code]' => 'tng-0500',
            'site_form[name]' => 'Tanger — Malabata',
            'site_form[city]' => 'Tanger',
            'site_form[region]' => 'Tanger-Tétouan-Al Hoceïma',
            'site_form[type]' => 'ANTENNE_5G',
        ]));

        self::assertResponseRedirects('/admin/sites');
        $site = self::em()->getRepository(Site::class)->findOneBy(['code' => 'TNG-0500']);
        self::assertNotNull($site, 'Le code est normalisé en majuscules');
        self::assertTrue($site->isActive(), 'Un nouveau site est actif par défaut');
    }

    public function testSiteValidation(): void
    {
        $crawler = $this->client->request('GET', '/admin/sites/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'site_form[code]' => AppFixtures::SITE_TANGER,
            'site_form[name]' => '',
            'site_form[city]' => 'Tanger',
            'site_form[region]' => 'Tanger-Tétouan-Al Hoceïma',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Ce code de site existe déjà');
    }

    public function testInvalidSiteCodeFormat(): void
    {
        $crawler = $this->client->request('GET', '/admin/sites/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'site_form[code]' => 'TANGER-1',
            'site_form[name]' => 'Code invalide',
            'site_form[city]' => 'Tanger',
            'site_form[region]' => 'Tanger-Tétouan-Al Hoceïma',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Format attendu');
    }

    public function testSiteWithIncidentsIsDeactivatedInsteadOfDeleted(): void
    {
        $site = self::em()->getRepository(Site::class)->findOneBy(['code' => AppFixtures::SITE_TANGER]);
        self::assertNotNull($site);
        $crawler = $this->client->request('GET', '/admin/sites');

        $this->client->submit($crawler->filter('form[action="/admin/sites/'.$site->getId().'/supprimer"]')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-warning', 'désactivé plutôt que supprimé');
        self::em()->clear();
        self::assertFalse(self::em()->getRepository(Site::class)->findOneBy(['code' => AppFixtures::SITE_TANGER])?->isActive());
    }

    public function testCreatedUserCanLogIn(): void
    {
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'user_form[firstName]' => 'Hamza',
            'user_form[lastName]' => 'Ouazzani',
            'user_form[email]' => 'Hamza.Ouazzani@netops.example',
            'user_form[role]' => 'ROLE_TECHNICIEN',
            'user_form[active]' => true,
            'user_form[plainPassword][first]' => 'Nouveau2026',
            'user_form[plainPassword][second]' => 'Nouveau2026',
        ]));
        self::assertResponseRedirects('/admin/utilisateurs');

        $user = self::em()->getRepository(User::class)->findOneBy(['email' => 'hamza.ouazzani@netops.example']);
        self::assertNotNull($user);
        self::assertNotSame('Nouveau2026', $user->getPassword());

        $this->client->request('GET', '/deconnexion');
        $this->client->restart();
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['email' => 'hamza.ouazzani@netops.example', 'password' => 'Nouveau2026']));
        $this->client->followRedirect();
        self::assertResponseRedirects('/mes-interventions');
    }

    public function testPasswordConfirmationMustMatch(): void
    {
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'user_form[firstName]' => 'A',
            'user_form[lastName]' => 'B',
            'user_form[email]' => 'ab@netops.example',
            'user_form[plainPassword][first]' => 'Motdepasse1',
            'user_form[plainPassword][second]' => 'Autre1234',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'ne correspondent pas');
    }

    public function testToggleUserActivation(): void
    {
        $agent = self::user(AppFixtures::AGENT_2);
        $crawler = $this->client->request('GET', '/admin/utilisateurs');

        $this->client->submit($crawler->filter('form[action="/admin/utilisateurs/'.$agent->getId().'/activation"]')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'désactivé');
        self::em()->clear();
        self::assertFalse(self::user(AppFixtures::AGENT_2)->isActive());
    }

    public function testAdminCannotDisableHimself(): void
    {
        $admin = self::user(AppFixtures::ADMIN);
        $this->client->request('GET', '/admin/utilisateurs');
        self::assertSelectorNotExists('form[action="/admin/utilisateurs/'.$admin->getId().'/activation"]');
    }

    public function testApiKeyIsShownOnceAndWorks(): void
    {
        $crawler = $this->client->request('GET', '/admin/cles-api/nouvelle');
        $crawler = $this->client->submit($crawler->selectButton('Générer la clé')->form(['api_client_form[name]' => 'Portail B2B (test)']));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $key = (string) $crawler->filter('[data-testid="api-key"]')->attr('value');
        self::assertMatchesRegularExpression('/^nop_[0-9a-f]{64}$/', $key);

        $client = self::em()->getRepository(ApiClient::class)->findOneBy(['name' => 'Portail B2B (test)']);
        self::assertNotNull($client);
        self::assertSame(hash('sha256', $key), $client->getKeyHash());

        $this->client->request('GET', '/admin/cles-api');
        self::assertStringNotContainsString($key, (string) $this->client->getResponse()->getContent());

        self::partnerRequest($this->client, 'GET', '/api/v1/sites', null, $key);
        self::assertResponseIsSuccessful();
    }

    public function testRevokedKeyIsRefused(): void
    {
        $client = self::em()->getRepository(ApiClient::class)->findOneBy(['name' => 'OSS Supervision Nord (démo)']);
        self::assertNotNull($client);
        $crawler = $this->client->request('GET', '/admin/cles-api');

        $this->client->submit($crawler->filter('form[action="/admin/cles-api/'.$client->getId().'/revoquer"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'révoquée');

        self::partnerRequest($this->client, 'GET', '/api/v1/sites');
        self::assertResponseStatusCodeSame(401);
    }
}
