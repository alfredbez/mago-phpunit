<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\TestCase;

abstract class CrossFileBaseProviderTestCase extends TestCase
{
    /** @return list<array{int, string}> */
    public static function validBaseRows(): array
    {
        return [
            [10, 'ten'],
            [20, 'twenty'],
        ];
    }

    /** @return list<array<int, mixed>> */
    public static function invalidBaseRows(): array
    {
        return [
            [10],
            ['bad-base-int', 'twenty'],
        ];
    }

    /** @return list<array<int, mixed>> */
    public static function dynamicBaseRows(): array
    {
        return self::buildDynamicBaseRows();
    }

    /** @return list<array<int, mixed>> */
    private static function buildDynamicBaseRows(): array
    {
        return [[10, 'dynamic']];
    }
}
