<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Intervention;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Intervention>
 */
class InterventionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Intervention::class);
    }

    /**
     * Interventions du technicien : en cours et planifiées d'abord, puis les terminées récentes.
     *
     * @return list<Intervention>
     */
    public function findForTechnician(User $technician, int $finishedLimit = 10): array
    {
        /** @var list<Intervention> $active */
        $active = $this->createQueryBuilder('iv')
            ->addSelect('i', 's')
            ->join('iv.incident', 'i')
            ->join('i.site', 's')
            ->andWhere('iv.technician = :tech')
            ->andWhere('iv.endedAt IS NULL')
            ->setParameter('tech', $technician)
            ->orderBy('iv.plannedAt', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var list<Intervention> $finished */
        $finished = $this->createQueryBuilder('iv')
            ->addSelect('i', 's')
            ->join('iv.incident', 'i')
            ->join('i.site', 's')
            ->andWhere('iv.technician = :tech')
            ->andWhere('iv.endedAt IS NOT NULL')
            ->setParameter('tech', $technician)
            ->orderBy('iv.endedAt', 'DESC')
            ->setMaxResults($finishedLimit)
            ->getQuery()
            ->getResult();

        return [...$active, ...$finished];
    }
}
