<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataProviderLifecycleOrderTest extends TestCase
{
    private static bool $ready = false;

    private static ?string $shared = null;

    private string $instanceValue;

    public static function setUpBeforeClass(): void
    {
        self::$ready = true;
        self::$shared = 'initialized';
    }

    public static function orderRows(): array
    {
        return [[self::$ready]];
    }

    #[DataProvider('orderRows')]
    public function testOrder(bool $ready): void
    {
        self::assertFalse($ready);
    }

    public static function sharedRows(): array
    {
        return [[self::$shared]];
    }

    #[DataProvider('sharedRows')]
    public function testSetUpBeforeClassDoesNotNarrowProviderValue(string $value): void
    {
        self::assertNotSame('', $value);
    }

    public function testInstancePropertyStillRequiresSetUp(): string
    {
        return $this->instanceValue;
    }
}
