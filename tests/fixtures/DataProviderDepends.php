<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

final class DataProviderDepends extends TestCase
{
    public function testProducer(): string { return 'dependency'; }
    public function testOtherProducer(): string { return 'other'; }

    public static function rows(): array { return [['provider', 42]]; }
    public static function shortRows(): array { return [['provider']]; }
    public static function wrongTypeRows(): array { return [[7, 42]]; }
    public static function longRows(): array { return [['provider', 42, 'extra', 'fourth']]; }

    #[DataProvider('rows')]
    #[Depends('testProducer')]
    #[Depends('testOtherProducer')]
    public function testValid(string $row, int $number, string $first, string $second): void {}

    #[DataProvider('shortRows')]
    #[Depends('testProducer')]
    public function testTooShort(string $row, int $number, string $dependency): void {}

    #[DataProvider('wrongTypeRows')]
    #[Depends('testProducer')]
    public function testWrongType(string $row, int $number, string $dependency): void {}

    #[DataProvider('longRows')]
    #[Depends('testProducer')]
    public function testTooLong(string $row, int $number, string $dependency): void {}
}
