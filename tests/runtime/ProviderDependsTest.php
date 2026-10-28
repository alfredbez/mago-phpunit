<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

final class ProviderDependsTest extends TestCase
{
    public function testFirstProducer(): string
    {
        self::assertTrue(true);
        return 'first dependency';
    }

    public function testSecondProducer(): string
    {
        self::assertTrue(true);
        return 'second dependency';
    }

    public static function rows(): array
    {
        return [['provider', 42]];
    }

    public static function longRows(): array { return [['provider', 42, 'extra']]; }

    #[DataProvider('longRows')]
    #[Depends('testFirstProducer')]
    public function testExtraArgument(string $row, int $number, string $third): void
    {
        self::assertSame(['provider', 42, 'extra', 'first dependency'], func_get_args());
    }

    #[DataProvider('rows')]
    #[Depends('testFirstProducer')]
    #[Depends('testSecondProducer')]
    public function testProviderArgumentsPrecedeDependencies(string $row, int $number, string $first, string $second): void
    {
        self::assertSame(['provider', 42, 'first dependency', 'second dependency'], [$row, $number, $first, $second]);
    }
}
