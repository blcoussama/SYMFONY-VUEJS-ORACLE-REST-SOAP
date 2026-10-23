<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiClient;
use App\Exception\BusinessRuleException;
use App\Repository\ApiClientRepository;
use App\Security\ApiKeyHasher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Création et révocation des clés d'API partenaires. */
final class ApiClientManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiClientRepository $repository,
        private readonly ApiKeyHasher $hasher,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{client: ApiClient, key: string} la clé en clair n'est renvoyée qu'ici, une seule fois
     */
    public function create(string $name): array
    {
        $name = trim($name);
        if ('' === $name) {
            throw new BusinessRuleException('Le nom du partenaire est obligatoire.', 'name');
        }
        if (null !== $this->repository->findOneBy(['name' => $name])) {
            throw new BusinessRuleException('Un partenaire porte déjà ce nom.', 'name');
        }

        $generated = $this->hasher->generate();
        $client = new ApiClient($name, $generated['prefix'], $generated['hash'], $this->clock->now());
        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return ['client' => $client, 'key' => $generated['key']];
    }

    public function revoke(ApiClient $client): void
    {
        if ($client->isActive()) {
            $client->revoke($this->clock->now());
            $this->entityManager->flush();
        }
    }
}
