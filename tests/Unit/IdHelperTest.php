<?php

declare(strict_types=1);

namespace OdxProxy\Tests\Unit;

use OdxProxy\Utils\IdHelper;
use PHPUnit\Framework\TestCase;

final class IdHelperTest extends TestCase
{
    public function testGenerateProduces26HexCharsAndIsRandom(): void
    {
        $id = IdHelper::generate();

        self::assertSame(26, strlen($id)); // 13 random bytes -> 26 hex chars
        self::assertMatchesRegularExpression('/^[0-9a-f]{26}$/', $id);
        self::assertNotSame(IdHelper::generate(), $id);
    }

    public function testNormalizeIdHandlesOdooPolymorphicValues(): void
    {
        self::assertNull(IdHelper::normalizeId(null));
        self::assertNull(IdHelper::normalizeId(false));
        self::assertSame('5', IdHelper::normalizeId(5));
        self::assertSame('5', IdHelper::normalizeId('5'));
        self::assertSame('42', IdHelper::normalizeId([42, 'Acme Corp'])); // Many2One [id, name]
    }
}
