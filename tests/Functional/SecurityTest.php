<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityTest extends WebTestCase
{
    use WebTestHelpers;

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/connexion');
        $client->submit($crawler->selectButton('Se connecter')->form(['email' => $email, 'password' => $password]));
    }

    public function testLoginPageIsAccessibleAnonymously(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_csrf_token"]');
        self::assertSelectorExists('label[for="email"]');
    }

    public function testSuccessfulLoginRedirectsToTheRoleHomePage(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, AppFixtures::SUPERVISOR, AppFixtures::DEMO_PASSWORD);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertResponseRedirects('/tableau-de-bord');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
    }

    public function testWrongPasswordIsRefused(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, AppFixtures::AGENT_1, 'mauvais-mot-de-passe');
        $client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Identifiants invalides');
    }

    public function testDisabledAccountCannotLogIn(): void
    {
        $client = static::createClient();
        self::user(AppFixtures::AGENT_2)->setActive(false);
        self::em()->flush();

        $this->submitLogin($client, AppFixtures::AGENT_2, AppFixtures::DEMO_PASSWORD);
        $client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Ce compte est désactivé');
    }

    public function testLoginRequiresAValidCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/connexion', ['email' => AppFixtures::AGENT_1, 'password' => AppFixtures::DEMO_PASSWORD, '_csrf_token' => 'faux']);
        $client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'CSRF');
    }

    public function testLoginIsThrottledAfterFiveFailures(): void
    {
        $client = static::createClient();
        for ($i = 0; $i < 6; ++$i) {
            $this->submitLogin($client, AppFixtures::TECHNICIAN_2, 'erreur-'.$i);
        }
        $client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'tentatives');
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/incidents');

        self::assertResponseRedirects('/connexion');
    }

    public function testLogoutRequiresTheCsrfLink(): void
    {
        $client = static::createClient();
        self::loginAs($client, AppFixtures::AGENT_1);
        $crawler = $client->request('GET', '/incidents');
        $logout = $crawler->selectLink('Déconnexion')->link();

        $client->click($logout);
        self::assertResponseRedirects('/connexion');
        $client->request('GET', '/incidents');
        self::assertResponseRedirects('/connexion');
    }
}
