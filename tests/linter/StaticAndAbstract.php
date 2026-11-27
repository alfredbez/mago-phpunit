<?php

declare(strict_types=1);

namespace Wave8Linter;

use PHPUnit\Framework\TestCase;

abstract class AbstractContractBase extends TestCase
{
    abstract public function testAbstractContract(): void;

    public function testOverriddenParent(): void
    {
        $this->assertTrue(true);
    }
}

final class StaticAndAbstract extends AbstractContractBase
{
    public function testAbstractContract(): void
    {
        $this->assertTrue(true);
    }

    public function testOverriddenParent(): void
    {
        parent::testOverriddenParent();
    }

    public function testInstanceScopes(bool $value): void
    {
        self::assertTrue($value);

        $staticClosure = static function () use ($value): void {
            self::assertTrue($value);
        };
        $staticArrow = static fn () => static::assertTrue($value);

        $boundClosure = function () use ($value): void {
            self::assertTrue($value);
        };
        $boundArrow = fn () => static::assertTrue($value);

        $staticClosure();
        $staticArrow();
        $boundClosure();
        $boundArrow();
    }

    public static function assertHelperInStaticMethod(bool $value): void
    {
        self::assertTrue($value);
        $closureInStatic = function () use ($value): void {
            static::assertTrue($value);
        };
        $closureInStatic();
    }
}
