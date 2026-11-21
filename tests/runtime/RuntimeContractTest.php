<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertEquals;

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
        assertArrayHasKey('7', [7 => 'value']);
        assertArrayHasKey(7, ['7' => 'value']);
        self::assertArrayHasKey('07', ['07' => 'value']);
        $this->expectException(AssertionFailedError::class);
        self::assertArrayHasKey('07', [7 => 'value']);
    }

    public function testPHPUNITA004LeadingZeroDoesNotProveInteger(): void
    {
        $this->expectException(AssertionFailedError::class);
        assertArrayHasKey(7, ['07' => 'value']);
    }

    public function testPHPUNITA005ArrayAccessIsNotAnArray(): void
    {
        $values = new ArrayObject(['token' => 'value', 'empty' => null]);
        self::assertArrayHasKey('token', $values);
        assertArrayHasKey('empty', $values);
        self::assertInstanceOf(ArrayObject::class, $values);
        self::assertSame(2, $values->count());
        self::assertNull($values->offsetGet('empty'));
        $this->expectException(AssertionFailedError::class);
        self::assertArrayHasKey('missing', $values);
    }

    public function testPHPUNITA006EqualityIsNotIdentity(): void
    {
        self::assertEquals(7, '7');
        assertEquals(7, '7');
        self::assertEquals(null, '');
        $this->expectException(AssertionFailedError::class);
        self::assertSame(7, '7');
    }

    public function testPHPUNITA006NullEqualsEmptyStringFailsIdentity(): void
    {
        self::assertEquals(null, '');
        $this->expectException(AssertionFailedError::class);
        self::assertSame(null, '');
    }
}
