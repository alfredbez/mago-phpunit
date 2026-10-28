<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

final class DataProviderDependsNamed extends TestCase
{
    public function testSource(): string { return 'dependency'; }

    public static function namedRows(): array { return [['row' => 'value']]; }

    #[DataProvider('namedRows')]
    #[Depends('testSource')]
    public function testNamed(string $row, string $dependency): void {}

    public static function unknownRows(): array { return [['unknown' => 'value']]; }

    #[DataProvider('unknownRows')]
    #[Depends('testSource')]
    public function testUnknown(string $row, string $dependency): void {}

    public static function wrongTypeRows(): array { return [['dependency' => 'value']]; }

    #[DataProvider('wrongTypeRows')]
    #[Depends('testSource')]
    public function testReordered(int $row, string $dependency): void {}

    #[DataProvider('namedRows')]
    public function testWithoutDependency(string $row, string $missing): void {}
}
