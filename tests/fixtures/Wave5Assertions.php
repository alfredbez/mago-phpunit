<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertJson;
use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertStringEndsWith;
use function PHPUnit\Framework\assertStringStartsWith;

final class Wave5AssertionsTest extends TestCase
{
    /** @return non-empty-string */
    public function testStringStartsWithNarrows(string $value): string
    {
        Assert::assertStringStartsWith('pre_', $value);

        return $value;
    }

    /** @return non-empty-string */
    public function testStringStartsWithFunctionNarrows(string $value): string
    {
        assertStringStartsWith('pre_', $value);

        return $value;
    }

    /** @return non-empty-string */
    public function testStringEndsWithNarrows(string $value): string
    {
        Assert::assertStringEndsWith('_suf', $value);

        return $value;
    }

    /** @return non-empty-string */
    public function testStringEndsWithFunctionNarrows(string $value): string
    {
        assertStringEndsWith('_suf', $value);

        return $value;
    }

    /** @return non-empty-string */
    public function testJsonNarrows(string $value): string
    {
        Assert::assertJson($value);

        return $value;
    }

    /** @return non-empty-string */
    public function testJsonFunctionNarrows(string $value): string
    {
        assertJson($value);

        return $value;
    }

    /** @return positive-int */
    public function testGreaterThanZeroNarrowsPositiveInt(int $n): int
    {
        Assert::assertGreaterThan(0, $n);

        return $n;
    }

    /** @return positive-int */
    public function testGreaterThanOrEqualOneFunctionNarrowsPositiveInt(int $n): int
    {
        assertGreaterThanOrEqual(1, $n);

        return $n;
    }

    /** @return negative-int */
    public function testLessThanZeroNarrowsNegativeInt(int $n): int
    {
        Assert::assertLessThan(0, $n);

        return $n;
    }

    /** @return negative-int */
    public function testLessThanOrEqualMinusOneFunctionNarrowsNegativeInt(int $n): int
    {
        assertLessThanOrEqual(-1, $n);

        return $n;
    }

    /** @return int<10, max> */
    public function testGreaterThanFunctionNarrowsRange(int $n): int
    {
        assertGreaterThan(9, $n);

        return $n;
    }

    /** @return int<min, 5> */
    public function testLessThanFunctionNarrowsRange(int $n): int
    {
        assertLessThan(6, $n);

        return $n;
    }

    public function testFloatComparisonDoesNotCorruptFloatToInt(float $f): float
    {
        Assert::assertGreaterThan(0, $f);

        return $f;
    }

    /** @return non-empty-string */
    public function testDynamicPrefixDoesNotNarrow(string $prefix, string $value): string
    {
        Assert::assertStringStartsWith($prefix, $value);

        return $value;
    }

    /** @return positive-int */
    public function testNonLiteralBoundDoesNotNarrow(int $min, int $n): int
    {
        Assert::assertGreaterThan($min, $n);

        return $n;
    }

    /** @return positive-int */
    public function testGreaterThanMinusOneDoesNotNarrowPositive(int $n): int
    {
        Assert::assertGreaterThan(-1, $n);

        return $n;
    }
}
