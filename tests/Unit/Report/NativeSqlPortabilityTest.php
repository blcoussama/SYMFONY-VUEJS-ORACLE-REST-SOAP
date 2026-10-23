<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\Report\NativeSqlReports;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Variantes SQL par plateforme du rapport natif (Oracle compris, sans base Oracle à disposition).
 */
final class NativeSqlPortabilityTest extends TestCase
{
    private function reports(AbstractPlatform $platform): NativeSqlReports
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return new NativeSqlReports($connection);
    }

    /**
     * @return iterable<string, array{AbstractPlatform, string}>
     */
    public static function durationExpressions(): iterable
    {
        yield 'Oracle' => [new OraclePlatform(), '(CAST(i.resolved_at AS DATE) - CAST(i.created_at AS DATE)) * 1440'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), 'EXTRACT(EPOCH FROM (i.resolved_at - i.created_at)) / 60'];
        yield 'SQLite' => [new SQLitePlatform(), '(julianday(i.resolved_at) - julianday(i.created_at)) * 1440'];
        yield 'MySQL' => [new MySQL80Platform(), 'TIMESTAMPDIFF(MINUTE, i.created_at, i.resolved_at)'];
    }

    #[DataProvider('durationExpressions')]
    public function testDurationExpressionPerPlatform(AbstractPlatform $platform, string $expected): void
    {
        $sql = $this->reports($platform)->resolutionTimeSql();

        self::assertStringContainsString('AVG('.$expected.') AS avg_minutes', $sql);
        self::assertStringContainsString('GROUP BY i.priority', $sql);
    }

    public function testUnsupportedPlatformIsExplicit(): void
    {
        $this->expectException(\LogicException::class);

        $this->reports(new SQLServerPlatform())->durationMinutesExpression('a', 'b');
    }

    public function testTopSitesLimitIsDelegatedToThePlatform(): void
    {
        self::assertStringEndsWith('FETCH NEXT 5 ROWS ONLY', $this->reports(new OraclePlatform())->topSitesSql(5));
        self::assertStringEndsWith('LIMIT 5', $this->reports(new PostgreSQLPlatform())->topSitesSql(5));
        self::assertStringEndsWith('LIMIT 5', $this->reports(new SQLitePlatform())->topSitesSql(5));
    }

    public function testTopSitesSqlIsPlainAnsiSql(): void
    {
        $sql = $this->reports(new OraclePlatform())->topSitesSql(5);

        self::assertStringContainsString("SUM(CASE WHEN i.status IN ('NOUVEAU', 'QUALIFIE', 'ASSIGNE', 'EN_COURS', 'EN_ATTENTE') THEN 1 ELSE 0 END) AS open_count", $sql);
        self::assertStringContainsString('ORDER BY COUNT(i.id) DESC, s.code ASC', $sql);
        self::assertStringNotContainsString('LIMIT', $sql);
    }
}
