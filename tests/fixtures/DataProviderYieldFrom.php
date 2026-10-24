<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataProviderYieldFromTest extends TestCase
{
    #[DataProvider('wrongCountRows')]
    public function testWrongCount(int $n, string $label): void
    {
    }

    public static function wrongCountRows(): iterable
    {
        yield from [
            [1, 'a'],
            [2],
        ];
        yield from [
            'ok' => [3, 'b'],
            'tooMany' => [4, 'c', true],
        ];
    }

    #[DataProvider('namedRows')]
    public function testNamed(int $n, string $label): void
    {
    }

    public static function namedRows(): iterable
    {
        yield from [
            'first' => ['n' => 1, 'label' => 'a'],
            'second' => ['n' => 2, 'label' => 'b', 'extra' => true],
        ];
    }

    #[DataProvider('wrongTypeRows')]
    public function testWrongType(int $n, string $label): void
    {
    }

    public static function wrongTypeRows(): iterable
    {
        yield from [
            [1, 'a'],
            ['wrong', 'b'],
        ];
    }

    #[DataProvider('mixedRows')]
    public function testMixed(int $n, string $label): void
    {
    }

    public static function mixedRows(): iterable
    {
        yield [1];
        yield from [
            [2, 'a'],
            'fromKey' => [3, 4],
        ];
        yield [5, 'b'];
    }

    #[DataProvider('matchingRows')]
    public function testMatching(int $n, string $label = 'default'): void
    {
    }

    public static function matchingRows(): iterable
    {
        yield from [
            [1, 'a'],
            [2],
        ];
        yield from [
            'first' => [3, 'b'],
            'second' => ['n' => 4, 'label' => 'c'],
        ];
    }

    #[DataProvider('nonLiteralRows')]
    public function testNonLiteral(int $n, string $label): void
    {
    }

    public static function nonLiteralRows(): iterable
    {
        $rows = [[1]];
        yield from $rows;
        yield from self::helperArray();
        yield from self::helperGenerator();
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
