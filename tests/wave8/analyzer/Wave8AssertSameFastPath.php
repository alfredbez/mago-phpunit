<?php

declare(strict_types=1);

namespace Wave8\AssertSameFastPath;

use PHPUnit\Framework\Assert as Check;
use PHPUnit\Framework\TestCase;

enum Marker { case One; }

final class SameArguments extends TestCase
{
    /** @param list<int> $values */
    public function testArguments(int|string $unknown, int|string $unknownExpected, int|string $unknownMessage, int|string $unknownComment, array $values): void
    {
        self::assertSame(1, $unknown);
        self::assertSame($unknownExpected, 1);
        self::assertSame(1, 1);
        self::assertSame(1, 2);
        self::assertSame(0, count($values));
        self::assertSame(count($values), 0);
        self::assertSame(actual: 3, expected: 3);
        self::assertSame(message: 'reordered', actual: count($values), expected: 2);
        self::assertSame(1, $unknownMessage, 'count: only a message');
        self::assertSame(1, $unknownComment /* count: only a comment */);
    }

    public function testSingletonKinds(): void
    {
        self::assertSame(null, null);
        self::assertSame(true, true);
        self::assertSame(false, false);
        self::assertSame('value', 'value');
        self::assertSame(1.5, 1.5);
        self::assertSame(Marker::One, Marker::One);
        $this->assertSame(4, 4);
        Check::assertSame('alias', 'alias');
        \PHPUnit\Framework\Assert::assertSame(5, 5);
        self::assertSame(message: 'first', actual: 6, expected: 6);
    }

    public function testMutableActual(): void
    {
        $actual = 1;
        $mutate = static function () use (&$actual): void { $actual = 2; };
        $mutate();
        self::assertSame(1, $actual);
    }

    public function testMutableExpected(): void
    {
        $expected = 1;
        $mutate = static function () use (&$expected): void { $expected = 2; };
        $mutate();
        self::assertSame($expected, 1);
    }
}
