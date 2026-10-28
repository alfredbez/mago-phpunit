<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertArrayHasKey;

final class RuntimeContractTest extends TestCase
{
    public function testPHPUNITA001LiteralKey(): void
    {
        self::assertArrayHasKey('token', ['token' => 'value']);
        $this->expectException(AssertionFailedError::class);
        self::assertArrayHasKey('token', ['different' => 'value']);
    }

    public function testPHPUNITA002NullIsPresent(): void
    {
        $values = ['token' => null];
        self::assertArrayHasKey('token', $values);
        self::assertNull($values['token']);
        $this->expectException(AssertionFailedError::class);
        self::assertArrayNotHasKey('token', $values);
    }

    public function testPHPUNITA003FunctionKey(): void
    {
        assertArrayHasKey('token', ['token' => null]);
        $this->expectException(AssertionFailedError::class);
        assertArrayHasKey('token', []);
    }

    public function testPHPUNITA004IntegerAndNumericString(): void
    {
        self::assertArrayHasKey('7', [7 => 'value']);
        self::assertArrayHasKey(7, ['7' => 'value']);
        $this->expectException(AssertionFailedError::class);
        self::assertArrayHasKey('07', [7 => 'value']);
    }

    public function testPHPUNITA005ArrayAccessIsNotAnArray(): void
    {
        $values = new ArrayObject(['token' => 'value']);
        self::assertArrayHasKey('token', $values);
        self::assertInstanceOf(ArrayObject::class, $values);
        $this->expectException(AssertionFailedError::class);
        self::assertArrayHasKey('missing', $values);
    }

    public function testPHPUNITA006EqualityIsNotIdentity(): void
    {
        self::assertEquals(7, '7');
        $this->expectException(AssertionFailedError::class);
        self::assertSame(7, '7');
    }
}
