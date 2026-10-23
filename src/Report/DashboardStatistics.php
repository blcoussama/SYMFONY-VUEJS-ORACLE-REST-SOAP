<?php

declare(strict_types=1);

namespace App\Report;

use App\Entity\Incident;
use App\Enum\IncidentPriority;
use App\Enum\IncidentStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Indicateurs du tableau de bord en DQL (QueryBuilder) : requêtes portables, agrégats SQL
 * standard (COUNT, SUM(CASE …)), aucun calcul de date propre à une base.
 */
final class DashboardStatistics
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Incidents ouverts par priorité (P1..P4, zéro compris).
     *
     * @return array<string, int>
     */
    public function openByPriority(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i.priority AS priority', 'COUNT(i.id) AS total')
            ->from(Incident::class, 'i')
            ->andWhere('i.status IN (:open)')
            ->setParameter('open', self::values(IncidentStatus::openStatuses()), ArrayParameterType::STRING)
            ->groupBy('i.priority')
            ->getQuery()
            ->getArrayResult();

        $result = array_fill_keys(array_map(static fn (IncidentPriority $p): string => $p->value, IncidentPriority::cases()), 0);
        foreach ($rows as $row) {
            $priority = $row['priority'] instanceof IncidentPriority ? $row['priority']->value : (string) $row['priority'];
            $result[$priority] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Nombre d'incidents par statut.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i.status AS status', 'COUNT(i.id) AS total')
            ->from(Incident::class, 'i')
            ->groupBy('i.status')
            ->getQuery()
            ->getArrayResult();

        $result = array_fill_keys(array_map(static fn (IncidentStatus $s): string => $s->value, IncidentStatus::cases()), 0);
        foreach ($rows as $row) {
            $status = $row['status'] instanceof IncidentStatus ? $row['status']->value : (string) $row['status'];
            $result[$status] = (int) $row['total'];
        }

        return $result;
    }

    /** Incidents ouverts dont l'échéance est dépassée. */
    public function breachedOpenCount(\DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(Incident::class, 'i')
            ->andWhere('i.status IN (:open)')
            ->andWhere('i.slaDueAt < :now')
            ->setParameter('open', self::values(IncidentStatus::openStatuses()), ArrayParameterType::STRING)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Taux de respect du SLA des incidents résolus depuis $since.
     *
     * @return array{resolved: int, met: int, rate: float|null}
     */
    public function slaCompliance(\DateTimeImmutable $since): array
    {
        /** @var array{resolved: int|string|null, met: int|string|null} $row */
        $row = $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id) AS resolved', 'SUM(CASE WHEN i.resolvedAt <= i.slaDueAt THEN 1 ELSE 0 END) AS met')
            ->from(Incident::class, 'i')
            ->andWhere('i.resolvedAt IS NOT NULL')
            ->andWhere('i.resolvedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        $resolved = (int) $row['resolved'];
        $met = (int) $row['met'];

        return ['resolved' => $resolved, 'met' => $met, 'rate' => $resolved > 0 ? round(100 * $met / $resolved, 1) : null];
    }

    /**
     * Tendance sur $days jours : incidents créés et résolus par jour (heure du Maroc).
     * Les dates sont regroupées en PHP pour rester portable (TRUNC / DATE() diffèrent selon les bases).
     *
     * @return list<array{day: string, label: string, created: int, resolved: int}>
     */
    public function trend(\DateTimeImmutable $now, int $days = 30): array
    {
        $tz = new \DateTimeZone('Africa/Casablanca');
        $localToday = $now->setTimezone($tz)->setTime(0, 0);
        $start = $localToday->modify(\sprintf('-%d days', $days - 1));

        $series = [];
        for ($d = $start; $d <= $localToday; $d = $d->modify('+1 day')) {
            $series[$d->format('Y-m-d')] = ['day' => $d->format('Y-m-d'), 'label' => $d->format('d/m'), 'created' => 0, 'resolved' => 0];
        }

        $since = $start->setTimezone(new \DateTimeZone('UTC'));
        foreach (['created' => 'createdAt', 'resolved' => 'resolvedAt'] as $key => $field) {
            $dates = $this->entityManager->createQueryBuilder()
                ->select('i.'.$field.' AS at')
                ->from(Incident::class, 'i')
                ->andWhere('i.'.$field.' >= :since')
                ->setParameter('since', $since)
                ->getQuery()
                ->getArrayResult();
            foreach ($dates as $row) {
                if (!$row['at'] instanceof \DateTimeImmutable) {
                    continue;
                }
                $day = $row['at']->setTimezone($tz)->format('Y-m-d');
                if (isset($series[$day])) {
                    ++$series[$day][$key];
                }
            }
        }

        return array_values($series);
    }

    /**
     * @param list<\BackedEnum> $enums
     *
     * @return list<string>
     */
    private static function values(array $enums): array
    {
        return array_map(static fn (\BackedEnum $e): string => (string) $e->value, $enums);
    }
}
