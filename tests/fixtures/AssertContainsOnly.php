<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertContainsOnly;

final class ContainsOnlyItem
{
    public function label(): string
    {
        return 'item';
    }
}

final class AssertContainsOnlyTest extends TestCase
{
    /** @param iterable<mixed> $items */
    public function testContainsOnlyString(iterable $items): string
    {
        Assert::assertContainsOnly('string', $items);
        foreach ($items as $item) {
            return $item;
        }

        return '';
    }

    /** @param iterable<mixed> $items */
    public function testContainsOnlyIntFunction(iterable $items): int
    {
        assertContainsOnly('int', $items);
        foreach ($items as $item) {
            return $item;
        }

        return 0;
    }

    /** @param iterable<mixed> $items */
    public function testContainsOnlyClassName(iterable $items): string
    {
        Assert::assertContainsOnly(ContainsOnlyItem::class, $items, false);
        foreach ($items as $item) {
            return $item->label();
        }

        return '';
    }

    /** @param iterable<mixed> $items */
    public function testContainsOnlyNonLiteralDoesNotNarrow(string $type, iterable $items): string
    {
        Assert::assertContainsOnly($type, $items);
        foreach ($items as $item) {
            return $item;
        }

        return '';
    }
}
