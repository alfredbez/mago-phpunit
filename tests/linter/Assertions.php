<?php

declare(strict_types=1);

namespace Wave8Linter;

use PHPUnit\Framework\Assert as Check;
use PHPUnit\Framework\TestCase as Base;

use function PHPUnit\Framework\assertEquals as equal;

abstract class ParentTest extends Base {}

final class Assertions extends ParentTest
{
    public function testExamples(mixed $value, array $arguments): void
    {
        $this->assertEquals(42, $value);
        self::assertNotEquals('x', $value);
        static::assertEquals(true, $value);
        equal(2, $value);
        Check::assertEquals(-2, $value);
        static::assertSame(null, $value, 'keep the message');
        static::assertSame(true, $value);
        static::assertSame(false, $value);
        static::assertNotSame(null, $value);
        static::assertEquals(null, $value);
        static::assertSame(actual: $value, expected: true);
        static::assertSame(null, ...$arguments);
        static::assertSame(null, /* keep this comment */ $value);
        static::assertEmpty($value);
        static::assertNotEmpty($value);
        static::assertCount(2, nested(1, 2));
        static::assertSame(null, nestedNamed(value: $value));
    }

    public static function testStaticExample(mixed $value): void
    {
        self::assertTrue($value);
    }
}
