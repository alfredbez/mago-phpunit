<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class HasKeyNullableTest extends TestCase
{
    /** @param array{k?: string|null} $values */
    public function testNullableReturn(array $values): ?string
    {
        $this->assertArrayHasKey('k', $values);

        return $values['k'];
    }

    /** @param array{k?: string|null} $values */
    public function testThisDoesNotExcludeNull(array $values): string
    {
        $this->assertArrayHasKey('k', $values);

        return $values['k'];
    }

    /** @param array{k?: string|null} $values */
    public function testSelfDoesNotExcludeNull(array $values): string
    {
        self::assertArrayHasKey('k', $values);

        return $values['k'];
    }

    /** @param array{k?: string|null} $values */
    public function testStaticDoesNotExcludeNull(array $values): string
    {
        static::assertArrayHasKey('k', $values);

        return $values['k'];
    }

    /** @param array{k?: string|null} $values */
    public function testAssertDoesNotExcludeNull(array $values): string
    {
        Assert::assertArrayHasKey('k', $values);

        return $values['k'];
    }

    /** @param array{k?: string|null} $values */
    public function testWithoutAssertion(array $values): ?string
    {
        return $values['k'];
    }

    /** @param array{k?: string|null, other?: string|null} $values */
    public function testOtherKey(array $values): ?string
    {
        self::assertArrayHasKey('k', $values);

        return $values['other'];
    }
}
