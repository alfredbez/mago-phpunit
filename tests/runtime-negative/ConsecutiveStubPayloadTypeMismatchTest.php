<?php

declare(strict_types=1);

use PHPUnit\Framework\MockObject\Stub\ConsecutiveCalls;
use PHPUnit\Framework\MockObject\Stub\ReturnArgument;
use PHPUnit\Framework\MockObject\Stub\ReturnSelf;
use PHPUnit\Framework\MockObject\Stub\ReturnStub;
use PHPUnit\Framework\MockObject\Stub\ReturnValueMap;
use PHPUnit\Framework\TestCase;

interface NegativeConsecutivePayloadTarget
{
    public function text(): string;

    public function echoText(string $text): string;

    public function pick(string|int ...$args): string;

    public function lookup(string $key): string;
}

final class ConsecutiveStubPayloadTypeMismatchTest extends TestCase
{
    public function testLocalReturnStubTypeMismatchThrowsTypeError(): void
    {
        $stub = $this->createStub(NegativeConsecutivePayloadTarget::class);
        $badStub = new ReturnStub(321);
        $stub->method('text')->willReturnOnConsecutiveCalls($badStub);

        self::assertNotSame('', $stub->text());
    }

    public function testReturnSelfTypeMismatchThrowsTypeError(): void
    {
        $stub = $this->createStub(NegativeConsecutivePayloadTarget::class);
        $stub->method('text')->willReturnOnConsecutiveCalls(new ReturnSelf());

        self::assertNotSame('', $stub->text());
    }

    public function testReturnArgumentUnionTypeMismatchThrowsTypeError(): void
    {
        $stub = $this->createStub(NegativeConsecutivePayloadTarget::class);
        $stub->method('pick')->willReturnOnConsecutiveCalls(new ReturnArgument(0));

        self::assertNotSame('', $stub->pick(123));
    }

    public function testReturnArgumentNegativeIndexThrowsTypeError(): void
    {
        $stub = $this->createStub(NegativeConsecutivePayloadTarget::class);
        $stub->method('echoText')->willReturnOnConsecutiveCalls(new ReturnArgument(-1));

        self::assertNotSame('', $stub->echoText('ok'));
    }

    public function testReturnArgumentOutOfRangeIndexThrowsTypeError(): void
    {
        $stub = $this->createStub(NegativeConsecutivePayloadTarget::class);
        $stub->method('echoText')->willReturnOnConsecutiveCalls(new ReturnArgument(2));

        self::assertNotSame('', $stub->echoText('ok'));
    }

    public function testReturnValueMapTypeMismatchThrowsTypeError(): void
    {
        $stub = $this->createStub(NegativeConsecutivePayloadTarget::class);
        $stub->method('lookup')->willReturnOnConsecutiveCalls(new ReturnValueMap([['a', 123]]));

        self::assertNotSame('', $stub->lookup('a'));
    }

    public function testNestedConsecutiveCallsTypeMismatchThrowsTypeError(): void
    {
        $stub = $this->createStub(NegativeConsecutivePayloadTarget::class);
        $stub->method('text')->willReturnOnConsecutiveCalls(new ConsecutiveCalls([123]));

        self::assertNotSame('', $stub->text());
    }
}
