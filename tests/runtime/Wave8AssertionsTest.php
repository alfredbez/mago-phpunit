<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\RuntimeTests;

use ArrayObject;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEmpty;
use function PHPUnit\Framework\assertIsList;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotCount;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertObjectHasProperty;

final class Wave8AssertionsTest extends TestCase
{
    public function testSuccessfulRuntimeContracts(): void
    {
        assertObjectHasProperty('name', (object) ['name' => null]);
        self::assertObjectHasProperty('name', (object) ['name' => 'value']);
        assertCount(2, ['a', 'b']);
        self::assertCount(0, new ArrayObject());
        assertNotCount(0, ['a']);
        self::assertNotCount(2, []);
        assertNotSame(null, '');
        self::assertNotSame(1, '1');
        assertContains(1, [1, '2']);
        self::assertContains('2', [1, '2']);
        assertNotContains('1', [1]);
        self::assertNotContains('absent', []);
        assertEmpty('0');
        self::assertEmpty(new ArrayObject());
        assertIsList(['a', 'b']);
        self::assertIsList([]);
    }

    public function testRuntimeCounterexamples(): void
    {
        foreach ([
            static fn () => assertObjectHasProperty('missing', (object) ['other' => 1]),
            static fn () => self::assertCount(2, ['a']),
            static fn () => assertNotCount(0, []),
            static fn () => assertNotSame('a', 'a'),
            static fn () => assertContains('1', [1]),
            static fn () => self::assertNotContains('a', ['a']),
            static fn () => assertEmpty(new ArrayObject(['a'])),
            static fn () => assertIsList([1 => 'a']),
        ] as $assertion) {
            $failed = false;
            try {
                $assertion();
            } catch (AssertionFailedError) {
                $failed = true;
            }
            self::assertTrue($failed);
        }
    }
}
