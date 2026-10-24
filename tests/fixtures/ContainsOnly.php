<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class Book
{
    public function title(): string
    {
        return 'book';
    }
}

final class ContainsOnlyTest extends TestCase
{
    /** @param list<mixed> $items */
    public function testThis(array $items): string
    {
        $this->assertContainsOnlyInstancesOf(Book::class, $items);

        $title = '';
        foreach ($items as $item) {
            $title = $item->title();
        }

        return $title;
    }

    /** @param list<mixed> $items */
    public function testSelf(array $items): string
    {
        self::assertContainsOnlyInstancesOf(Book::class, $items);

        $title = '';
        foreach ($items as $item) {
            $title = $item->title();
        }

        return $title;
    }

    /** @param list<mixed> $items */
    public function testStatic(array $items): string
    {
        static::assertContainsOnlyInstancesOf(Book::class, $items);

        $title = '';
        foreach ($items as $item) {
            $title = $item->title();
        }

        return $title;
    }

    /** @param list<mixed> $items */
    public function testAssertClass(array $items): string
    {
        Assert::assertContainsOnlyInstancesOf(Book::class, $items);

        $title = '';
        foreach ($items as $item) {
            $title = $item->title();
        }

        return $title;
    }

    /**
     * A non-literal class name must not turn the values into Book.
     *
     * @param list<mixed> $items
     */
    public function testNonLiteral(array $items, string $class): string
    {
        $this->assertContainsOnlyInstancesOf($class, $items);

        $title = '';
        foreach ($items as $item) {
            $title = $item->title();
        }

        return $title;
    }

    /**
     * Narrowing to another class must not make Book methods available.
     *
     * @param list<mixed> $items
     */
    public function testOtherClass(array $items): string
    {
        $this->assertContainsOnlyInstancesOf(TestCase::class, $items);

        $title = '';
        foreach ($items as $item) {
            $title = $item->title();
        }

        return $title;
    }
}
