<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use ArrayAccess;
use ArrayObject;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertArrayHasKey;

/**
 * @implements ArrayAccess<array-key, ?string>
 */
final class NullableBox implements ArrayAccess
{
    /** @var array<array-key, ?string> */
    private array $items;

    /** @param array<array-key, ?string> $items */
    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    public function offsetGet(mixed $offset): ?string
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->items[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
}

final class HasKeyArrayAccessTest extends TestCase
{
    /** @param ArrayObject<array-key, mixed> $values */
    public function testArrayObjectRetainsMethods(ArrayObject $values): int
    {
        Assert::assertArrayHasKey('k', $values);

        return $values->count();
    }

    /** @param ArrayObject<array-key, mixed> $values */
    public function testArrayObjectFunctionRetainsMethods(ArrayObject $values): int
    {
        assertArrayHasKey('k', $values);

        return $values->count();
    }

    /** @param ArrayAccess<array-key, mixed> $values */
    public function testArrayAccessRetainsMethods(ArrayAccess $values): bool
    {
        $this->assertArrayHasKey('k', $values);

        return $values->offsetExists('k');
    }

    /** @param ArrayObject<array-key, mixed> $values */
    public function testArrayObjectDoesNotBecomeArray(ArrayObject $values): array
    {
        Assert::assertArrayHasKey('k', $values);

        return $values;
    }

    /** @param ArrayAccess<array-key, mixed> $values */
    public function testArrayAccessFunctionDoesNotBecomeArray(ArrayAccess $values): array
    {
        assertArrayHasKey('k', $values);

        return $values;
    }

    /** @param ArrayObject<array-key, mixed> $values */
    public function testArrayObjectDoesNotWidenToMixed(ArrayObject $values): void
    {
        Assert::assertArrayHasKey('k', $values);
        $values->nonExistentMethod();
    }

    public function testCustomArrayAccessValueRemainsNullable(): string
    {
        $box = new NullableBox(['k' => null]);
        Assert::assertArrayHasKey('k', $box);

        return $box->offsetGet('k');
    }
}
