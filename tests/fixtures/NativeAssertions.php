<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class InstanceTarget
{
    public function label(): string
    {
        return 'target';
    }
}

final class NativeAssertionsTest extends TestCase
{
    public function testInstanceOfThis(?InstanceTarget $x): InstanceTarget
    {
        $this->assertInstanceOf(InstanceTarget::class, $x);

        return $x;
    }

    public function testInstanceOfSelf(mixed $x): string
    {
        self::assertInstanceOf(InstanceTarget::class, $x);

        return $x->label();
    }

    public function testInstanceOfStatic(InstanceTarget|string $x): InstanceTarget
    {
        static::assertInstanceOf(InstanceTarget::class, $x);

        return $x;
    }

    public function testInstanceOfAssertClass(?InstanceTarget $x): InstanceTarget
    {
        Assert::assertInstanceOf(InstanceTarget::class, $x);

        return $x;
    }

    public function testInstanceOfNonLiteral(string $class, mixed $x): InstanceTarget
    {
        $this->assertInstanceOf($class, $x);

        return $x;
    }

    public function testInstanceOfOtherVariable(?InstanceTarget $x, ?InstanceTarget $other): InstanceTarget
    {
        $this->assertInstanceOf(InstanceTarget::class, $x);

        return $other;
    }

    public function testNullThis(?int $x): null
    {
        $this->assertNull($x);

        return $x;
    }

    public function testNullSelf(mixed $x): null
    {
        self::assertNull($x);

        return $x;
    }

    public function testNullStatic(int|null $x): null
    {
        static::assertNull($x);

        return $x;
    }

    public function testNullAssertClass(?string $x): null
    {
        Assert::assertNull($x);

        return $x;
    }

    public function testNullOtherVariable(?int $x, ?int $other): null
    {
        $this->assertNull($x);

        return $other;
    }

    public function testSameLiteralThis(int|string $x): int
    {
        $this->assertSame(1, $x);

        return $x;
    }

    public function testSameTypedSelf(int $expected, int|string $x): int
    {
        self::assertSame($expected, $x);

        return $x;
    }

    public function testSameStatic(InstanceTarget $expected, ?InstanceTarget $x): InstanceTarget
    {
        static::assertSame($expected, $x);

        return $x;
    }

    public function testSameAssertClass(string $expected, mixed $x): string
    {
        Assert::assertSame($expected, $x);

        return $x;
    }

    public function testSameUnknownExpected(mixed $expected, int|string $x): int
    {
        $this->assertSame($expected, $x);

        return $x;
    }

    public function testSameOtherVariable(int $expected, int|string $x, int|string $other): int
    {
        $this->assertSame($expected, $x);

        return $other;
    }
}
