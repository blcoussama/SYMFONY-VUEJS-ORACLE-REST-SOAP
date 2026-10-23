<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiClient>
 */
class ApiClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiClient::class);
    }

    public function findOneByKeyHash(string $keyHash): ?ApiClient
    {
        return $this->findOneBy(['keyHash' => $keyHash]);
    }

    /**
     * @return list<ApiClient>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['active' => 'DESC', 'name' => 'ASC']);
    }
}
