<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\IncidentFilter;
use App\Dto\Page;
use App\Entity\Incident;
use App\Entity\Site;
use App\Entity\User;
use App\Enum\IncidentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Incident>
 */
class IncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

    public function findOneByReference(string $reference): ?Incident
    {
        return $this->findOneBy(['reference' => strtoupper(trim($reference))]);
    }

    /**
     * Recherche paginée. DQL portable : LIKE avec caractère d'échappement explicite
     * (« ! »), LOWER(), IN, comparaisons de dates par paramètre — rien de propre à une base.
     *
     * @return Page<Incident>
     */
    public function search(IncidentFilter $filter, \DateTimeImmutable $now): Page
    {
        $qb = $this->filteredQueryBuilder($filter, $now);

        $total = (int) (clone $qb)
            ->select('COUNT(i.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $perPage = max(1, min(100, $filter->perPage));
        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($filter->page, $pageCount));

        $qb->addSelect('s', 'a')
            ->leftJoin('i.assignee', 'a')
            ->orderBy('i.'.$filter->normalizedSort(), $filter->normalizedDirection())
            ->addOrderBy('i.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        /** @var list<Incident> $items */
        $items = $qb->getQuery()->getResult();

        return new Page($items, $total, $page, $perPage);
    }

    public function filteredQueryBuilder(IncidentFilter $filter, \DateTimeImmutable $now): QueryBuilder
    {
        $qb = $this->createQueryBuilder('i')->join('i.site', 's');

        if ([] !== $filter->statuses) {
            $qb->andWhere('i.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (IncidentStatus $s): string => $s->value, $filter->statuses), ArrayParameterType::STRING);
        }
        if (null !== $filter->priority) {
            $qb->andWhere('i.priority = :priority')->setParameter('priority', $filter->priority->value);
        }
        if ($filter->site instanceof Site) {
            $qb->andWhere('i.site = :site')->setParameter('site', $filter->site);
        } elseif (null !== $filter->siteCode && '' !== $filter->siteCode) {
            $qb->andWhere('s.code = :siteCode')->setParameter('siteCode', strtoupper($filter->siteCode));
        }
        if (null !== $filter->region && '' !== $filter->region) {
            $qb->andWhere('s.region = :region')->setParameter('region', $filter->region);
        }
        if ($filter->assignee instanceof User) {
            $qb->andWhere('i.assignee = :assignee')->setParameter('assignee', $filter->assignee);
        }
        if ($filter->breachedOnly) {
            $qb->andWhere('i.status IN (:openStatuses)')
                ->andWhere('i.slaDueAt < :now')
                ->setParameter('openStatuses', array_map(static fn (IncidentStatus $s): string => $s->value, IncidentStatus::openStatuses()), ArrayParameterType::STRING)
                ->setParameter('now', $now);
        }
        $query = trim((string) $filter->query);
        if ('' !== $query) {
            $like = '%'.mb_strtolower(strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_'])).'%';
            $qb->andWhere(
                "LOWER(i.reference) LIKE :q ESCAPE '!' OR LOWER(i.title) LIKE :q ESCAPE '!' OR LOWER(s.code) LIKE :q ESCAPE '!' OR LOWER(s.name) LIKE :q ESCAPE '!'"
            )->setParameter('q', $like);
        }

        return $qb;
    }

    /**
     * Incidents du tableau Kanban : tous les ouverts + les résolus/clos/annulés récents.
     *
     * @return list<Incident>
     */
    public function findForKanban(\DateTimeImmutable $since, int $limit = 200): array
    {
        /** @var list<Incident> $result */
        $result = $this->createQueryBuilder('i')
            ->addSelect('s', 'a')
            ->join('i.site', 's')
            ->leftJoin('i.assignee', 'a')
            ->andWhere('i.status IN (:open) OR i.updatedAt >= :since')
            ->setParameter('open', array_map(static fn (IncidentStatus $s): string => $s->value, IncidentStatus::openStatuses()), ArrayParameterType::STRING)
            ->setParameter('since', $since)
            ->orderBy('i.priority', 'ASC')
            ->addOrderBy('i.slaDueAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Incidents ouverts assignés à un technicien (page « Mes interventions »).
     *
     * @return list<Incident>
     */
    public function findOpenAssignedTo(User $technician): array
    {
        /** @var list<Incident> $result */
        $result = $this->createQueryBuilder('i')
            ->addSelect('s')
            ->join('i.site', 's')
            ->andWhere('i.assignee = :tech')
            ->andWhere('i.status IN (:open)')
            ->setParameter('tech', $technician)
            ->setParameter('open', array_map(static fn (IncidentStatus $s): string => $s->value, IncidentStatus::openStatuses()), ArrayParameterType::STRING)
            ->orderBy('i.slaDueAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Incidents ouverts d'un site (opération SOAP listOpenIncidentsBySite).
     *
     * @return list<Incident>
     */
    public function findOpenBySite(Site $site): array
    {
        /** @var list<Incident> $result */
        $result = $this->createQueryBuilder('i')
            ->leftJoin('i.assignee', 'a')
            ->addSelect('a')
            ->andWhere('i.site = :site')
            ->andWhere('i.status IN (:open)')
            ->setParameter('site', $site)
            ->setParameter('open', array_map(static fn (IncidentStatus $s): string => $s->value, IncidentStatus::openStatuses()), ArrayParameterType::STRING)
            ->orderBy('i.priority', 'ASC')
            ->addOrderBy('i.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
