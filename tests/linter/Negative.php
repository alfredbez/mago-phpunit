<?php

declare(strict_types=1);

namespace Wave8Linter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

function assertEquals(mixed $expected, mixed $actual): void {}

class Other
{
    public function testUnrelated(mixed $value): void
    {
        $this->assertEquals(1, $value);
        self::assertSame(null, $value);
        static::assertEmpty($value);
    }

    public function assertEquals(mixed $expected, mixed $actual): void {}

    public static function assertSame(mixed $expected, mixed $actual): void {}

    public static function assertEmpty(mixed $value): void {}
}

final class Negative extends TestCase
{
    #[Test]
    #[DataProvider('rows')]
    public function example(mixed $value, string $method, Other $other): void
    {
        static::assertSame('x', $value);
        static::assertEquals($value, 1);
        static::assertCount(0, []);
        $this->{$method}(true, $value);
        $other->assertEquals(1, $value);
        assertEquals(1, $value);
        $anonymous = new class {
            public function example(mixed $value): void
            {
                $this->assertSame(null, $value);
            }

            public function assertSame(mixed $expected, mixed $actual): void {}
        };
    }

    public function TestNotDiscovered(): void {}

    public static function rows(): array
    {
        return [[1]];
    }
}
