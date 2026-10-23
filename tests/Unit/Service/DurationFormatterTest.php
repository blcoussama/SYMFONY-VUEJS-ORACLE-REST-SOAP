<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Sla\DurationFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationFormatterTest extends TestCase
{
    /**
     * @return iterable<array{int, string}>
     */
    public static function durations(): iterable
    {
        yield [0, 'moins d’une minute'];
        yield [59, 'moins d’une minute'];
        yield [60, '1 min'];
        yield [3600, '1 h'];
        yield [3600 + 5 * 60, '1 h 05 min'];
        yield [86400, '1 j'];
        yield [2 * 86400 + 3 * 3600 + 59, '2 j 3 h'];
        yield [-7200, '2 h'];
    }

    #[DataProvider('durations')]
    public function testFormat(int $seconds, string $expected): void
    {
        self::assertSame($expected, DurationFormatter::format($seconds));
    }

    public function testFormatMinutes(): void
    {
        self::assertSame('—', DurationFormatter::formatMinutes(null));
        self::assertSame('2 h 30 min', DurationFormatter::formatMinutes(150.0));
    }
}
