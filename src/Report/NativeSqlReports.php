<?php

declare(strict_types=1);

namespace App\Report;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;

/**
 * Rapports du tableau de bord en SQL natif (Doctrine DBAL).
 *
 * 1. topSites()       : SQL ANSI (JOIN, GROUP BY, SUM(CASE …)) identique sur toutes les bases ;
 *                       seule la limitation « 5 premières lignes » est déléguée à la plateforme DBAL
 *                       (FETCH FIRST n ROWS ONLY sur Oracle 12c+, LIMIT ailleurs).
 * 2. resolutionTime() : la différence de deux horodatages n'est pas normalisée en SQL ; l'expression
 *                       « durée en minutes » est choisie selon la plateforme :
 *                       - Oracle     : (CAST(b AS DATE) - CAST(a AS DATE)) * 1440  (DATE - DATE = jours)
 *                       - PostgreSQL : EXTRACT(EPOCH FROM (b - a)) / 60
 *                       - SQLite     : (julianday(b) - julianday(a)) * 1440
 *                       - MySQL      : TIMESTAMPDIFF(MINUTE, a, b)
 *
 * Oracle renvoie les noms de colonnes en majuscules : les clés sont ramenées en minuscules.
 * Les dates sont liées comme paramètres typés (Types::DATETIME_IMMUTABLE) ; sur Oracle, le format
 * de session est fixé par App\Doctrine\OracleSessionMiddleware.
 */
final class NativeSqlReports
{
    private const OPEN_STATUSES = "'NOUVEAU', 'QUALIFIE', 'ASSIGNE', 'EN_COURS', 'EN_ATTENTE'";

    public function __construct(private readonly Connection $connection)
    {
    }

    public function topSitesSql(int $limit = 5): string
    {
        $sql = <<<SQL
            SELECT s.code AS site_code,
                   s.name AS site_name,
                   s.region AS region,
                   COUNT(i.id) AS incident_count,
                   SUM(CASE WHEN i.status IN (%s) THEN 1 ELSE 0 END) AS open_count,
                   SUM(CASE WHEN i.priority = 'P1' THEN 1 ELSE 0 END) AS p1_count
              FROM nop_incident i
              JOIN nop_site s ON s.id = i.site_id
             WHERE i.created_at >= :since
             GROUP BY s.code, s.name, s.region
             ORDER BY COUNT(i.id) DESC, s.code ASC
            SQL;

        return $this->connection->getDatabasePlatform()->modifyLimitQuery(\sprintf($sql, self::OPEN_STATUSES), $limit);
    }

    /**
     * Top des sites en incident depuis $since.
     *
     * @return list<array{site_code: string, site_name: string, region: string, incident_count: int, open_count: int, p1_count: int}>
     */
    public function topSites(\DateTimeImmutable $since, int $limit = 5): array
    {
        $rows = $this->connection->fetchAllAssociative($this->topSitesSql($limit), ['since' => $since], ['since' => Types::DATETIME_IMMUTABLE]);

        return array_map(static function (array $row): array {
            $row = array_change_key_case($row, \CASE_LOWER);

            return [
                'site_code' => (string) $row['site_code'],
                'site_name' => (string) $row['site_name'],
                'region' => (string) $row['region'],
                'incident_count' => (int) $row['incident_count'],
                'open_count' => (int) $row['open_count'],
                'p1_count' => (int) $row['p1_count'],
            ];
        }, $rows);
    }

    public function durationMinutesExpression(string $from, string $to): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof OraclePlatform => \sprintf('(CAST(%2$s AS DATE) - CAST(%1$s AS DATE)) * 1440', $from, $to),
            $platform instanceof PostgreSQLPlatform => \sprintf('EXTRACT(EPOCH FROM (%2$s - %1$s)) / 60', $from, $to),
            $platform instanceof SQLitePlatform => \sprintf('(julianday(%2$s) - julianday(%1$s)) * 1440', $from, $to),
            $platform instanceof AbstractMySQLPlatform => \sprintf('TIMESTAMPDIFF(MINUTE, %1$s, %2$s)', $from, $to),
            default => throw new \LogicException(\sprintf('Plateforme non prise en charge : %s', $platform::class)),
        };
    }

    public function resolutionTimeSql(): string
    {
        return \sprintf(<<<'SQL'
            SELECT i.priority AS priority,
                   COUNT(i.id) AS resolved_count,
                   AVG(%s) AS avg_minutes
              FROM nop_incident i
             WHERE i.resolved_at IS NOT NULL
               AND i.resolved_at >= :since
             GROUP BY i.priority
             ORDER BY i.priority
            SQL, $this->durationMinutesExpression('i.created_at', 'i.resolved_at'));
    }

    /**
     * Temps moyen de résolution (minutes) par priorité, et global (moyenne pondérée).
     *
     * @return array{byPriority: array<string, array{count: int, avgMinutes: float}>, overall: float|null, resolved: int}
     */
    public function resolutionTime(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative($this->resolutionTimeSql(), ['since' => $since], ['since' => Types::DATETIME_IMMUTABLE]);

        $byPriority = [];
        $total = 0;
        $weighted = 0.0;
        foreach ($rows as $row) {
            $row = array_change_key_case($row, \CASE_LOWER);
            $count = (int) $row['resolved_count'];
            $avg = round((float) $row['avg_minutes'], 1);
            $byPriority[(string) $row['priority']] = ['count' => $count, 'avgMinutes' => $avg];
            $total += $count;
            $weighted += $avg * $count;
        }

        return ['byPriority' => $byPriority, 'overall' => $total > 0 ? round($weighted / $total, 1) : null, 'resolved' => $total];
    }
}
