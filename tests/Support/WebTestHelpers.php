<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DataFixtures\AppFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Outils communs aux tests fonctionnels et d'API.
 */
trait WebTestHelpers
{
    use FixtureLookup;

    protected static function loginAs(KernelBrowser $client, string $email): void
    {
        $client->loginUser(static::user($email));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers en-têtes HTTP (ex. ['X-API-Key' => '...'])
     */
    protected static function jsonRequest(KernelBrowser $client, string $method, string $uri, ?array $body = null, array $headers = []): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $client->request($method, $uri, [], [], $server, null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected static function partnerRequest(KernelBrowser $client, string $method, string $uri, ?array $body = null, string $key = AppFixtures::DEMO_API_KEY): void
    {
        static::jsonRequest($client, $method, $uri, $body, ['X-API-Key' => $key]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** Valeur d'un attribut lu dans la page (ex. jeton CSRF d'un formulaire rendu). */
    protected static function attributeFrom(KernelBrowser $client, string $uri, string $selector, string $attribute = 'value'): string
    {
        $crawler = $client->request('GET', $uri);
        self::assertResponseIsSuccessful();
        $node = $crawler->filter($selector);
        self::assertGreaterThan(0, $node->count(), "Élément introuvable : $selector");

        return (string) $node->first()->attr($attribute);
    }
}
