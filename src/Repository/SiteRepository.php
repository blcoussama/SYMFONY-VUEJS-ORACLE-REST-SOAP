<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Site>
 */
class SiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Site::class);
    }

    public function findOneActiveByCode(string $code): ?Site
    {
        return $this->findOneBy(['code' => strtoupper(trim($code)), 'active' => true]);
    }

    public function activeQueryBuilder(?string $region = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.active = :active')
            ->setParameter('active', true)
            ->orderBy('s.region', 'ASC')
            ->addOrderBy('s.code', 'ASC');

        if (null !== $region && '' !== $region) {
            $qb->andWhere('s.region = :region')->setParameter('region', $region);
        }

        return $qb;
    }

    /**
     * @return list<Site>
     */
    public function findActive(?string $region = null): array
    {
        /** @var list<Site> $result */
        $result = $this->activeQueryBuilder($region)->getQuery()->getResult();

        return $result;
    }

    /**
     * Régions des sites actifs, pour les listes déroulantes.
     *
     * @return list<string>
     */
    public function findRegions(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.region AS region')
            ->andWhere('s.active = :active')
            ->setParameter('active', true)
            ->orderBy('s.region', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['region'], $rows));
    }

    /**
     * @return list<Site>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['region' => 'ASC', 'code' => 'ASC']);
    }
}
