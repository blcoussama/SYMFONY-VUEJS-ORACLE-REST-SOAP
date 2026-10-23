<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\OracleSessionMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use PHPUnit\Framework\TestCase;

final class OracleSessionMiddlewareTest extends TestCase
{
    /**
     * @param array<string, mixed> $params
     */
    private function connect(array $params): ?string
    {
        $executed = null;
        $connection = $this->createStub(DriverConnection::class);
        $connection->method('exec')->willReturnCallback(static function (string $sql) use (&$executed): int {
            $executed = $sql;

            return 0;
        });
        $driver = $this->createStub(Driver::class);
        $driver->method('connect')->willReturn($connection);

        (new OracleSessionMiddleware())->wrap($driver)->connect($params);

        return $executed;
    }

    public function testOracleSessionFormatsAreSet(): void
    {
        $sql = (string) $this->connect(['driver' => 'oci8']);

        self::assertStringContainsString("NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS'", $sql);
        self::assertStringContainsString("NLS_NUMERIC_CHARACTERS = '.,'", $sql);
    }

    public function testOtherDriversAreUntouched(): void
    {
        self::assertNull($this->connect(['driver' => 'pdo_pgsql']));
        self::assertNull($this->connect(['driver' => 'pdo_sqlite']));
    }
}
