<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractApiController
{
    /** Sonde publique : 200 si la base répond, 503 sinon (sans détail technique). */
    #[Route('/api/v1/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(Connection $connection, ClockInterface $clock): JsonResponse
    {
        try {
            // « SELECT 1 » portable : FROM DUAL est ajouté sur Oracle par la plateforme DBAL.
            $connection->fetchOne($connection->getDatabasePlatform()->getDummySelectSQL());
            $database = 'ok';
        } catch (\Throwable) {
            $database = 'indisponible';
        }

        $response = $this->json([
            'status' => 'ok' === $database ? 'ok' : 'degraded',
            'database' => $database,
            'time' => $clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ], 'ok' === $database ? 200 : 503);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
