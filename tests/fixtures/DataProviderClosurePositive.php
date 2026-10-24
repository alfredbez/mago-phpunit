<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderClosure;
use PHPUnit\Framework\TestCase;

final class DataProviderClosurePositiveTest extends TestCase
{
    #[DataProviderClosure(static function (): array {
        return [
            [1, 'a'],
            [2],
            'tooMany' => [3, 'b', true],
        ];
    })]
    public function testReturnCount(int $n, string $label): void
    {
    }

    #[DataProviderClosure(static function (): iterable {
        yield [1, 'a'];
        yield [2];
        yield 'bad';
    })]
    public function testYieldCount(int $n, string $label): void
    {
    }

    #[DataProviderClosure(static function (): iterable {
        yield from [
            [1, 'a'],
            [2],
        ];
    })]
    public function testYieldFromCount(int $n, string $label): void
    {
    }

    #[DataProviderClosure(static function (): iterable {
        yield ['n' => 1, 'label' => 'a'];
        yield ['n' => 2];
        yield from [
            'extraKey' => ['n' => 3, 'label' => 'c', 'extra' => true],
        ];
    })]
    public function testNamedParameters(int $n, string $label): void
    {
    }

    #[DataProviderClosure(static function (): array {
        return [
            ['wrong', 'a'],
        ];
    })]
    #[DataProviderClosure(static function (): iterable {
        yield [1, 2];
        yield from [
            'fromWrong' => ['n' => 1.5, 'label' => 'b'],
        ];
    })]
    public function testWrongTypes(int $n, string $label): void
    {
    }

    #[DataProvider('validMethodRows')]
    #[DataProviderClosure(static function (): array {
        return [
            [1],
        ];
    })]
    public function testMixedWithMethod(int $n, string $label): void
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
            ['bad', 'a', true],
        ];
    }, false)]
    public function testValidateCountFalseKeepsTypeCheck(int $n, string $label): void
    {
    }
}
