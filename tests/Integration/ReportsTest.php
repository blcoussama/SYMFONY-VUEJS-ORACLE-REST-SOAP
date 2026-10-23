<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Report\DashboardStatistics;
use App\Report\NativeSqlReports;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Indicateurs du tableau de bord sur les données de démonstration (exécutés sur la base de test réelle). */
final class ReportsTest extends KernelTestCase
{
    private DashboardStatistics $statistics;
    private NativeSqlReports $reports;

    protected function setUp(): void
    {
        self::bootKernel();
        $statistics = self::getContainer()->get(DashboardStatistics::class);
        $reports = self::getContainer()->get(NativeSqlReports::class);
        \assert($statistics instanceof DashboardStatistics && $reports instanceof NativeSqlReports);
        $this->statistics = $statistics;
        $this->reports = $reports;
    }

    public function testOpenByPriorityCountsTheOpenIncidents(): void
    {
        $byPriority = $this->statistics->openByPriority();
        $byStatus = $this->statistics->countByStatus();

        self::assertSame(['P1', 'P2', 'P3', 'P4'], array_keys($byPriority));
        self::assertSame(
            $byStatus['NOUVEAU'] + $byStatus['QUALIFIE'] + $byStatus['ASSIGNE'] + $byStatus['EN_COURS'] + $byStatus['EN_ATTENTE'],
            array_sum($byPriority),
        );
        self::assertSame(40, array_sum($byStatus));
    }

    public function testSlaComplianceRate(): void
    {
        $compliance = $this->statistics->slaCompliance(new \DateTimeImmutable('-31 days'));

        self::assertSame(12, $compliance['resolved']);
        self::assertGreaterThan(0, $compliance['met']);
        self::assertLessThan(12, $compliance['met']);
        self::assertSame(round(100 * $compliance['met'] / 12, 1), $compliance['rate']);
    }

    public function testTrendCovers30DaysAndAllCreations(): void
    {
        $trend = $this->statistics->trend(new \DateTimeImmutable(), 30);

        self::assertCount(30, $trend);
        self::assertSame(40, array_sum(array_column($trend, 'created')));
    }

    public function testBreachedCountMatchesTheFilter(): void
    {
        self::assertGreaterThan(0, $this->statistics->breachedOpenCount(new \DateTimeImmutable()));
    }

    public function testNativeTopSitesReport(): void
    {
        $top = $this->reports->topSites(new \DateTimeImmutable('-31 days'), 5);

        self::assertCount(5, $top);
        self::assertSame('TNG-0042', $top[0]['site_code']);
        $counts = array_column($top, 'incident_count');
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts);
        self::assertStringContainsString('GROUP BY s.code, s.name, s.region', $this->reports->topSitesSql());
    }

    public function testNativeResolutionTimeReport(): void
    {
        $resolution = $this->reports->resolutionTime(new \DateTimeImmutable('-31 days'));

        self::assertSame(12, $resolution['resolved']);
        self::assertNotNull($resolution['overall']);
        foreach ($resolution['byPriority'] as $row) {
            self::assertGreaterThan(0, $row['avgMinutes']);
        }
    }
}
