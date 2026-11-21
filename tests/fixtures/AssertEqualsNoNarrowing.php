<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertEquals;

final class AssertEqualsNoNarrowingTest extends TestCase
{
    public function testEqualsThisDoesNotNarrowStringToInt(string $actual): int
    {
        $this->assertEquals(7, $actual);

        return $actual;
    }

    public function testEqualsSelfDoesNotNarrowUnion(int|string $actual): int
    {
        self::assertEquals(7, $actual);

        return $actual;
    }

    public function testEqualsStaticDoesNotNarrowBoolUnion(int|bool $actual): bool
    {
        static::assertEquals(true, $actual);

        return $actual;
    }

    public function testEqualsAssertClassDoesNotNarrowToNull(?string $actual): null
    {
        Assert::assertEquals(null, $actual);

        return $actual;
    }

    public function testEqualsFunctionDoesNotNarrowStringToInt(string $actual): int
    {
        assertEquals(7, $actual);

        return $actual;
    }

    public function testSameContrastNarrowsUnionToInt(int|string $actual): int
    {
        Assert::assertSame(7, $actual);

        return $actual;
    }
}
