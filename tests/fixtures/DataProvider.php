<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class DataProviderTest extends ParentRowsTest
{
    #[DataProvider('matchingRows')]
    public function testMatching(int $n, string $label): void
    {
    }

    public static function matchingRows(): array
    {
        return [
            [1, 'a'],
            'named' => [2, 'b'],
        ];
    }

    #[DataProvider('optionalRows')]
    public function testOptional(int $n, string $label = 'x'): void
    {
    }

    public static function optionalRows(): array
    {
        return [
            [1],
            [2, 'y'],
        ];
    }

    #[DataProvider('variadicRows')]
    public function testVariadic(int $n, string ...$rest): void
    {
    }

    public static function variadicRows(): array
    {
        return [
            [1],
            [2, 'a', 'b'],
        ];
    }

    #[DataProvider('shortRows')]
    public function testTooFew(int $n, string $label): void
    {
    }

    public static function shortRows(): array
    {
        return [
            [1, 'a'],
            [2],
        ];
    }

    #[DataProvider('longRows')]
    public function testTooMany(int $n, string $label): void
    {
    }

    #[DataProvider('longRows', false)]
    public function testTooManyAllowed(int $n, string $label): void
    {
    }

    public static function longRows(): array
    {
        return [
            [1, 'a', true],
        ];
    }

    #[DataProvider('yieldedRows')]
    public function testYield(int $n, string $label): void
    {
    }

    public static function yieldedRows(): iterable
    {
        yield [1, 'a'];
        yield [2];
        yield 'short' => [3];
        yield 'bad';
    }

    /**
     * @dataProvider docblockRows
     */
    public function testDocblock(int $n, string $label): void
    {
    }

    public static function docblockRows(): array
    {
        return [
            'ok' => [1, 'a'],
            [2, 'b', true],
        ];
    }

    #[DataProvider('namedRows')]
    public function testNamed(int $n, string $label): void
    {
    }

    public static function namedRows(): array
    {
        return [
            ['n' => 1, 'label' => 'a'],
            ['n' => 2],
            ['n' => 3, 'label' => 'c', 'extra' => true],
        ];
    }

    #[DataProvider('conditionalRows')]
    public function testConditional(int $n, string $label): void
    {
    }

    public static function conditionalRows(bool $flag): array
    {
        if ($flag) {
            return [[1, 'a']];
        }

        return [[2]];
    }

    #[DataProvider('variableRows')]
    public function testVariable(int $n, string $label): void
    {
    }

    public static function variableRows(): array
    {
        $rows = [[1], [2, 'a', true]];

        return $rows;
    }

    #[DataProvider('builtRows')]
    public function testBuilt(int $n, string $label): void
    {
    }

    public static function builtRows(): array
    {
        $rows = [];
        $rows[] = [1];
        $rows[] = [2, 'a'];

        return $rows;
    }

    #[DataProviderExternal(ExternalRows::class, 'rows')]
    public function testExternal(int $n): void
    {
    }

    #[DataProvider('shortRows')]
    #[DataProvider('matchingRows')]
    public function testRepeated(int $n, string $label): void
    {
    }

    #[DataProvider('parentRows')]
    public function testParent(int $n, string $label): void
    {
    }
}

class ParentRowsTest extends TestCase
{
    public static function parentRows(): array
    {
        return [
            [1, 'a'],
            [2],
        ];
    }
}

final class ExternalRows
{
    public static function rows(): array
    {
        return [
            [1],
            [2, 3],
        ];
    }
}

final class NotATest
{
    #[DataProvider('rows')]
    public function testNotChecked(int $n, string $label): void
    {
    }

    public static function rows(): array
    {
        return [
            [1],
        ];
    }
}
