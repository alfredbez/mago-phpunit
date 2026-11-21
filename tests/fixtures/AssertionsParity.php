<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use ArrayObject;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringContainsStringIgnoringCase;

final class AssertionsParityTest extends TestCase
{
    /** @param array{k?: string, other?: string} $a */
    public function testArrayNotHasKeyForbidsAssertedKey(array $a): void
    {
        Assert::assertArrayNotHasKey('k', $a);
        $missing = $a['k'];
        $other = $a['other'];
        unset($missing, $other);
    }

    /** @param array{k?: string} $a */
    public function testArrayNotHasKeyFunction(array $a): void
    {
        assertArrayNotHasKey('k', $a);
        $missing = $a['k'];
        unset($missing);
    }

    /** @param array{k?: string} $a */
    public function testArrayNotHasKeyNonLiteralDoesNotForbid(array $a, string $key): void
    {
        Assert::assertArrayNotHasKey($key, $a);
        $maybe = $a['k'];
        unset($maybe);
    }

    /** @param ArrayObject<array-key, mixed> $values */
    public function testArrayNotHasKeyKeepsArrayObject(ArrayObject $values): int
    {
        Assert::assertArrayNotHasKey('k', $values);

        return $values->count();
    }

    /**
     * @param list<string> $items
     * @return non-empty-list<string>
     */
    public function testCountPositiveNarrowsNonEmptyList(array $items): array
    {
        Assert::assertCount(2, $items);

        return $items;
    }

    /**
     * @param array<string, int> $items
     * @return non-empty-array<string, int>
     */
    public function testCountFunctionPositiveNarrowsNonEmptyArray(array $items): array
    {
        assertCount(1, $items);

        return $items;
    }

    /**
     * @param list<string> $items
     * @return non-empty-list<string>
     */
    public function testCountZeroDoesNotNarrowNonEmpty(array $items): array
    {
        Assert::assertCount(0, $items);

        return $items;
    }

    /**
     * @param list<string> $items
     * @return non-empty-list<string>
     */
    public function testCountNonLiteralDoesNotNarrowNonEmpty(int $expected, array $items): array
    {
        Assert::assertCount($expected, $items);

        return $items;
    }

    /** @param ArrayObject<array-key, mixed> $values */
    public function testCountKeepsCountableObject(ArrayObject $values): int
    {
        Assert::assertCount(1, $values);

        return $values->count();
    }

    /**
     * @param list<string> $items
     * @return non-empty-list<string>
     */
    public function testNotEmptyNarrowsList(array $items): array
    {
        Assert::assertNotEmpty($items);

        return $items;
    }

    /**
     * @return non-empty-string
     */
    public function testNotEmptyFunctionNarrowsString(string $value): string
    {
        assertNotEmpty($value);

        return $value;
    }

    public function testNotEmptyNarrowsNullable(?AssertionsParityTarget $target): AssertionsParityTarget
    {
        $this->assertNotEmpty($target);

        return $target;
    }

    /** @param ArrayObject<array-key, mixed> $values */
    public function testNotEmptyKeepsCountableObject(ArrayObject $values): int
    {
        Assert::assertNotEmpty($values);

        return $values->count();
    }

    /**
     * @return non-empty-string
     */
    public function testStringContainsStringNarrowsHaystack(string $haystack): string
    {
        Assert::assertStringContainsString('needle', $haystack);

        return $haystack;
    }

    /**
     * @return non-empty-string
     */
    public function testStringContainsStringFunctionNarrowsHaystack(string $haystack): string
    {
        assertStringContainsString('needle', $haystack);

        return $haystack;
    }

    /**
     * @return non-empty-string
     */
    public function testStringContainsStringIgnoringCaseFunction(string $haystack): string
    {
        assertStringContainsStringIgnoringCase('needle', $haystack);

        return $haystack;
    }

    /**
     * @return non-empty-string
     */
    public function testStringContainsEmptyNeedleDoesNotNarrow(string $haystack): string
    {
        Assert::assertStringContainsString('', $haystack);

        return $haystack;
    }

    /**
     * @return non-empty-string
     */
    public function testStringContainsDynamicNeedleDoesNotNarrow(string $needle, string $haystack): string
    {
        Assert::assertStringContainsString($needle, $haystack);

        return $haystack;
    }

    /**
     * @param non-empty-string $needle
     * @return non-empty-string
     */
    public function testStringContainsNonEmptyTypeNeedleNarrows(string $needle, string $haystack): string
    {
        Assert::assertStringContainsString($needle, $haystack);

        return $haystack;
    }
}

final class AssertionsParityTarget {}
