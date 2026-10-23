<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class HasKeyPositiveTest extends TestCase
{
    /** @param array{k?: string, other?: string} $arr */
    public function testThis(array $arr): string
    {
        $this->assertArrayHasKey('k', $arr);

        return $arr['k'];
    }

    /** @param array{k?: string} $arr */
    public function testSelf(array $arr): string
    {
        self::assertArrayHasKey('k', $arr);

        return $arr['k'];
    }

    /** @param array{k?: string} $arr */
    public function testStatic(array $arr): string
    {
        static::assertArrayHasKey('k', $arr);

        return $arr['k'];
    }

    /** @param array{k?: string} $arr */
    public function testAssertClass(array $arr): string
    {
        Assert::assertArrayHasKey('k', $arr);

        return $arr['k'];
    }

    /** @param array{0?: string} $arr */
    public function testIntegerKey(array $arr): string
    {
        $this->assertArrayHasKey(0, $arr);

        return $arr[0];
    }
}
