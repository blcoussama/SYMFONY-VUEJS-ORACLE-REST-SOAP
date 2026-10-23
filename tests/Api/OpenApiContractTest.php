<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/** docs/openapi.yaml reste aligné sur les routes réelles de l'API. */
final class OpenApiContractTest extends KernelTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        $spec = Yaml::parseFile(\dirname(__DIR__, 2).'/docs/openapi.yaml');
        self::assertIsArray($spec);

        return $spec;
    }

    public function testEveryApiRouteIsDocumented(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $paths = self::spec()['paths'];
        self::assertIsArray($paths);

        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/')) {
                continue;
            }
            self::assertArrayHasKey($route->getPath(), $paths, "Route $name non documentée");
            foreach ($route->getMethods() as $method) {
                self::assertArrayHasKey(strtolower($method), $paths[$route->getPath()], "Méthode $method de $name non documentée");
            }
        }
    }

    public function testSpecDeclaresBothAuthenticationSchemesAndProblemJson(): void
    {
        $spec = self::spec();

        self::assertSame('3.1.0', $spec['openapi']);
        self::assertSame(['apiKey', 'session'], array_keys($spec['components']['securitySchemes']));
        self::assertArrayHasKey('application/problem+json', $spec['components']['responses']['ValidationError']['content']);
    }
}
