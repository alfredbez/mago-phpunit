<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataProviderVariadicNamedTest extends TestCase
{
    public static function validNamedVariadicRows(): array
    {
        return [
            ['n' => 1, 'first' => 'a', 'second' => 'b'],
            ['n' => 2, 'rest' => 'c'],
        ];
    }

    #[DataProvider('validNamedVariadicRows')]
    public function testValidNamedVariadic(int $n, string ...$rest): void
    {
    }

    public static function reorderedNamedVariadicRows(): array
    {
        return [
            ['first' => 'a', 'n' => 1],
        ];
    }

    #[DataProvider('reorderedNamedVariadicRows')]
    public function testReorderedNamedVariadic(int $n, string ...$rest): void
    {
    }

    public static function wrongTypeVariadicRows(): array
    {
        return [
            ['n' => 1, 'first' => 42],
            ['n' => 2, 'rest' => 99],
        ];
    }

    #[DataProvider('wrongTypeVariadicRows')]
    public function testWrongTypeNamedVariadic(int $n, string ...$rest): void
    {
    }

    public static function missingRequiredVariadicRows(): array
    {
        return [
            ['first' => 'a'],
        ];
    }

    #[DataProvider('missingRequiredVariadicRows')]
    public function testMissingRequiredNamedVariadic(int $n, string ...$rest): void
    {
    }

    /** @return list<array{n: int, first: string}> */
    public static function nonLiteralNamedRows(): array
    {
        $row = ['n' => 1, 'first' => 'a'];

        return [$row];
    }

    #[DataProvider('nonLiteralNamedRows')]
    public function testNonLiteralNamedRowFallsBackSafely(int $n, string ...$rest): void
    {
    }
}
