<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderClosure;
use PHPUnit\Framework\TestCase;

final class DataProviderClosureCounterTest extends TestCase
{
    #[DataProvider('validMethodRows')]
    #[DataProviderClosure(static function (): array {
        $nested = static function (): array {
            return [[1]];
        };
        unset($nested);

        return [
            [1, 'a'],
            'namedRow' => ['label' => 'b', 'n' => 2],
        ];
    })]
    #[DataProviderClosure(static function (): iterable {
        yield [3];
        yield from [
            [4, 'd'],
        ];
    })]
    public function testMatching(int $n, string $label = 'default'): void
    {
    }

    public static function validMethodRows(): array
    {
        return [
            [1, 'a'],
        ];
    }

    #[DataProviderClosure(static function (): array {
        return [
            [1, 'a', true],
        ];
    }, false)]
    public function testTooManyAllowedPositional(int $n, string $label): void
    {
    }

    #[DataProviderClosure(
        closure: static function (): array {
            return [
                [1, 'a', true],
            ];
        },
        validateArgumentCount: false,
    )]
    public function testTooManyAllowedNamed(int $n, string $label): void
    {
    }

    #[DataProviderClosure(static function (): array {
        $rows = [];
        $rows[] = [1];

        return $rows;
    })]
    #[DataProviderClosure(static function (): array {
        return self::helperArray();
    })]
    #[DataProviderClosure(static function (): iterable {
        $rows = [[1]];
        yield from $rows;
        yield from self::helperArray();
        yield from self::helperGenerator();
    })]
    public function testNonLiteralBody(int $n, string $label): void
    {
    }

    #[DataProviderClosure(static function () use ($captured): array {
        return [[1]];
    })]
    public function testClosureWithUse(int $n, string $label): void
    {
    }

    private static function helperArray(): array
    {
        return [[1]];
    }

    private static function helperGenerator(): iterable
    {
        yield [1];
    }
}

final class NotAClosureTest
{
    #[DataProviderClosure(static function (): array {
        return [[1]];
    })]
    public function testNotChecked(int $n, string $label): void
    {
    }
}
