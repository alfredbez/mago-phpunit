<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderLifecycleOrderTest extends TestCase
{
    private static bool $beforeClassRan = false;

    private static bool $setUpRan = false;

    /** @var list<string> */
    private static array $events = [];

    public static function setUpBeforeClass(): void
    {
        self::$beforeClassRan = true;
        self::$events[] = 'setUpBeforeClass';
    }

    protected function setUp(): void
    {
        self::$setUpRan = true;
        self::$events[] = 'setUp';
    }

    /** @return list<array{bool, bool}> */
    public static function lifecycleRows(): array
    {
        self::$events[] = 'provider';

        return [[self::$beforeClassRan, self::$setUpRan]];
    }

    #[DataProvider('lifecycleRows')]
    public function testProviderExecutesBeforeLifecycleHooks(bool $beforeClassAtProvide, bool $setUpAtProvide): void
    {
        self::assertFalse($beforeClassAtProvide);
        self::assertFalse($setUpAtProvide);
        self::assertTrue(self::$beforeClassRan);
        self::assertTrue(self::$setUpRan);
        self::assertSame(['provider', 'setUpBeforeClass', 'setUp'], array_slice(self::$events, 0, 3));
    }
}
