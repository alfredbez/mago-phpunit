<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataProviderTypesTest extends TestCase
{
    #[DataProvider('stringForInt')]
    public function testStringForInt(int $n, string $label): void
    {
    }

    public static function stringForInt(): array
    {
        return [
            ['1', 'a'],
            'swapped' => ['b', 2],
        ];
    }

    #[DataProvider('yieldedStringForInt')]
    public function testYieldedStringForInt(int $n): void
    {
    }

    public static function yieldedStringForInt(): iterable
    {
        yield [1];
        yield 'text' => ['x'];
    }

    #[DataProvider('namedWrongType')]
    public function testNamedWrongType(int $n, string $label): void
    {
    }

    public static function namedWrongType(): array
    {
        return [
            ['label' => 'a', 'n' => 1.5],
        ];
    }

    #[DataProvider('variadicWrongType')]
    public function testVariadicWrongType(int $n, string ...$rest): void
    {
    }

    public static function variadicWrongType(): array
    {
        return [
            [1, 'a', 2],
        ];
    }

    #[DataProvider('compatibleRows')]
    public function testCompatible(int $n, float $ratio, ?string $label, Shape $shape, bool $flag): void
    {
    }

    public static function compatibleRows(): array
    {
        return [
            [1, 2, null, new Square(), true],
            [2, 0.5, 'a', new Square(), false],
            'named' => ['shape' => new Square(), 'flag' => true, 'n' => 3, 'ratio' => 4, 'label' => null],
        ];
    }

    #[DataProvider('looseRows')]
    public function testLoose($untyped, mixed $anything): void
    {
    }

    public static function looseRows(): array
    {
        return [
            ['a', 1],
            [new Square(), null],
        ];
    }

    #[DataProvider('nonLiteralRows')]
    public function testNonLiteral(int $n, string $label): void
    {
    }

    public static function nonLiteralRows($unknown): array
    {
        return [
            [$unknown, 'a'],
            [self::unknownValue(), self::LABEL],
            [strlen('x'), strtoupper('a')],
        ];
    }

    private static function unknownValue()
    {
        return 'x';
    }

    private const LABEL = 'label';
}

class Shape
{
}

final class Square extends Shape
{
}
