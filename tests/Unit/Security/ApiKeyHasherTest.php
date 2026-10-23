<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ApiKeyHasher;
use PHPUnit\Framework\TestCase;

final class ApiKeyHasherTest extends TestCase
{
    public function testGeneratedKeysAreRandomPrefixedAndHashed(): void
    {
        $hasher = new ApiKeyHasher();
        $a = $hasher->generate();
        $b = $hasher->generate();

        self::assertMatchesRegularExpression('/^nop_[0-9a-f]{64}$/', $a['key']);
        self::assertNotSame($a['key'], $b['key']);
        self::assertSame(hash('sha256', $a['key']), $a['hash']);
        self::assertSame(substr($a['key'], 0, 12), $a['prefix']);
        self::assertStringNotContainsString(substr($a['key'], 12), $a['prefix'].$a['hash']);
    }

    public function testHashIgnoresSurroundingWhitespace(): void
    {
        self::assertSame(ApiKeyHasher::hash('nop_abc'), ApiKeyHasher::hash(" nop_abc\n"));
    }
}
