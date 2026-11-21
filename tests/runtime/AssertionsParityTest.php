<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringContainsStringIgnoringCase;

final class AssertionsParityTest extends TestCase
{
    public function testArrayNotHasKeyRuntimeContract(): void
    {
        self::assertArrayNotHasKey('missing', ['present' => 'value']);
        assertArrayNotHasKey('missing', new ArrayObject(['present' => 'value']));
        $this->expectException(AssertionFailedError::class);
        self::assertArrayNotHasKey('present', ['present' => null]);
    }

    public function testCountRuntimeContract(): void
    {
        self::assertCount(2, ['a', 'b']);
        assertCount(0, []);
        self::assertCount(1, new ArrayObject(['a']));
        $this->expectException(AssertionFailedError::class);
        self::assertCount(1, []);
    }

    public function testNotEmptyRuntimeContract(): void
    {
        self::assertNotEmpty(['a']);
        assertNotEmpty('a');
        self::assertNotEmpty(new ArrayObject(['a']));
        $this->expectException(AssertionFailedError::class);
        self::assertNotEmpty('0');
    }

    public function testNotEmptyRejectsEmptyCountable(): void
    {
        $this->expectException(AssertionFailedError::class);
        self::assertNotEmpty(new ArrayObject([]));
    }

    public function testStringContainsStringRuntimeContract(): void
    {
        self::assertStringContainsString('', '');
        assertStringContainsString('a', 'abc');
        assertStringContainsStringIgnoringCase('A', 'abc');
        $this->expectException(AssertionFailedError::class);
        self::assertStringContainsString('a', '');
    }
}
