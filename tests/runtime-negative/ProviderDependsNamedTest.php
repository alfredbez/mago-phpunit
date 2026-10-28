<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

final class ProviderDependsNamedTest extends TestCase
{
    public function testProducer(): string
    {
        self::assertTrue(true);
        return 'dependency';
    }

    public static function rows(): array
    {
        return [['row' => 'provider']];
    }

    #[DataProvider('rows')]
    #[Depends('testProducer')]
    public function testNamed(string $row, string $dependency): void
    {
        self::assertSame(['provider', 'dependency'], [$row, $dependency]);
    }
}
