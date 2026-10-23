<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Reference\IncidentReferenceGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReferenceGeneratorTest extends KernelTestCase
{
    private IncidentReferenceGenerator $generator;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $generator = self::getContainer()->get(IncidentReferenceGenerator::class);
        $connection = self::getContainer()->get(Connection::class);
        \assert($generator instanceof IncidentReferenceGenerator && $connection instanceof Connection);
        $this->generator = $generator;
        $this->connection = $connection;
    }

    public function testFirstReferenceOfANewYearStartsAtOne(): void
    {
        self::assertSame('INC-2031-000001', $this->generator->next(new \DateTimeImmutable('2031-01-01 00:05')));
        self::assertSame('INC-2031-000002', $this->generator->next(new \DateTimeImmutable('2031-06-30 12:00')));
        self::assertSame('INC-2032-000001', $this->generator->next(new \DateTimeImmutable('2032-01-01 00:00')));
    }

    public function testContinuesTheExistingCounter(): void
    {
        $year = (int) date('Y');
        $current = (int) $this->connection->fetchOne('SELECT current_value FROM nop_ref_counter WHERE counter_key = ?', ['INC-'.$year]);

        self::assertSame(\sprintf('INC-%d-%06d', $year, $current + 1), $this->generator->next(new \DateTimeImmutable()));
    }

    public function testReferencesAreUniqueInsideOneTransaction(): void
    {
        $references = [];
        $this->connection->transactional(function () use (&$references): void {
            for ($i = 0; $i < 50; ++$i) {
                $references[] = $this->generator->next(new \DateTimeImmutable('2033-05-01'));
            }
        });

        self::assertCount(50, array_unique($references));
        self::assertSame('INC-2033-000050', end($references));
    }
}
