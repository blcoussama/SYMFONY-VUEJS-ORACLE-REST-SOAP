<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiClient;
use App\Repository\ApiClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Retrouve le partenaire actif correspondant à une clé d'API (utilisé par le service SOAP ;
 * l'API REST passe par ApiKeyAuthenticator, sur le même principe).
 */
final class ApiClientResolver
{
    public function __construct(
        private readonly ApiClientRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function resolve(?string $key): ?ApiClient
    {
        $key = trim((string) $key);
        if ('' === $key) {
            return null;
        }

        $client = $this->repository->findOneByKeyHash(ApiKeyHasher::hash($key));
        if (null === $client || !$client->isActive()) {
            return null;
        }

        $client->markUsed($this->clock->now());
        $this->entityManager->flush();

        return $client;
    }
}
