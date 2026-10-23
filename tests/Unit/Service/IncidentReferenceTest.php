<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Reference\IncidentReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IncidentReferenceTest extends TestCase
{
    public function testFormatPadsTheSequenceOnSixDigits(): void
    {
        self::assertSame('INC-2026-000042', IncidentReference::format(2026, 42));
        self::assertSame('INC-2027-999999', IncidentReference::format(2027, 999_999));
    }

    public function testSequenceOverflowIsRejected(): void
    {
        $this->expectException(\OverflowException::class);

        IncidentReference::format(2026, 1_000_000);
    }

    public function testSequenceMustBePositive(): void
    {
        $this->expectException(\OverflowException::class);

        IncidentReference::format(2026, 0);
    }

    public function testYearOutOfRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IncidentReference::format(1999, 1);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function references(): iterable
    {
        yield 'valide' => ['INC-2026-000001', true];
        yield 'séquence nulle' => ['INC-2026-000000', false];
        yield 'minuscules' => ['inc-2026-000001', false];
        yield 'trop court' => ['INC-2026-00001', false];
        yield 'mauvais préfixe' => ['REQ-2026-000001', false];
        yield 'injection' => ["INC-2026-000001' OR '1'='1", false];
    }

    #[DataProvider('references')]
    public function testValidation(string $reference, bool $valid): void
    {
        self::assertSame($valid, IncidentReference::isValid($reference));
    }

    public function testParse(): void
    {
        self::assertSame(['year' => 2026, 'sequence' => 123], IncidentReference::parse('INC-2026-000123'));
    }

    public function testParseRejectsInvalidReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IncidentReference::parse('INC-26-1');
    }

    public function testCounterKeyIsPerYear(): void
    {
        self::assertSame('INC-2026', IncidentReference::counterKey(2026));
    }
}
