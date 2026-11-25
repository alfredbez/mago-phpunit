<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Runtime;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\Stub\Exception as ExceptionStub;
use PHPUnit\Framework\MockObject\Stub\ReturnCallback;
use PHPUnit\Framework\MockObject\Stub\ReturnStub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

interface RuntimeInterfaceConstants
{
    public const IFACE_TEXT = 'iface';
}

trait RuntimeTraitConstants
{
    public const TRAIT_CODE = 7;
}

class RuntimeParentConstants
{
    public const PARENT_TEXT = 'parent';
}

final class RuntimeProviderConstants extends RuntimeParentConstants implements RuntimeInterfaceConstants
{
    use RuntimeTraitConstants;

    public const int ANSWER = 42;
    public const string TEXT = 'valid';
    public const ALIAS = self::ANSWER;
}

enum RuntimeConstantEnum: string
{
    case Active = 'active';
    public const DEFAULT_CODE = 200;
    public const DEFAULT_CASE = self::Active;
}

interface ConsecutivePayloadTarget
{
    public function result(): string;

    public function nullable(): ?string;

    public function abort(): never;
}

final class ConsecutiveStubPayloadsAndTestWithConstantsTest extends TestCase
{
    public function testConsecutiveCallsAcceptsValidReturnStubAndReturnCallback(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);
        $stub->method('result')->willReturnOnConsecutiveCalls(
            new ReturnStub('valid'),
            new ReturnCallback(static fn(): string => 'ok'),
            new ExceptionStub(new RuntimeException('boom')),
        );

        self::assertSame('valid', $stub->result());
        self::assertSame('ok', $stub->result());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $stub->result();
    }

    public function testConsecutiveCallsInvalidReturnStubThrowsTypeError(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);
        $stub->method('result')->willReturnOnConsecutiveCalls(new ReturnStub(123));

        $this->expectException(TypeError::class);
        $stub->result();
    }

    public function testConsecutiveCallsInvalidReturnCallbackThrowsTypeError(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);
        $stub->method('result')->willReturnOnConsecutiveCalls(new ReturnCallback(static fn(): array => []));

        $this->expectException(TypeError::class);
        $stub->result();
    }

    public function testConsecutiveCallsVoidCallbackReturnsNullForNullableAndThrowsForNonNullable(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);
        $stub->method('nullable')->willReturnOnConsecutiveCalls(new ReturnCallback(static function (): void {}));
        self::assertNull($stub->nullable());

        $stub->method('result')->willReturnOnConsecutiveCalls(new ReturnCallback(static function (): void {}));
        $this->expectException(TypeError::class);
        $stub->result();
    }

    public function testConsecutiveCallsAcceptsExceptionStubOnNeverReturn(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);
        $stub->method('abort')->willReturnOnConsecutiveCalls(new ExceptionStub(new RuntimeException('never-boom')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never-boom');
        $stub->abort();
    }

    #[TestWith([RuntimeProviderConstants::ANSWER])]
    #[TestWith([RuntimeProviderConstants::ALIAS])]
    #[TestWith([RuntimeProviderConstants::TRAIT_CODE])]
    #[TestWith([RuntimeConstantEnum::DEFAULT_CODE])]
    public function testIntClassConstantsRun(int $value): void
    {
        self::assertGreaterThan(0, $value);
    }

    #[TestWith([RuntimeProviderConstants::TEXT])]
    #[TestWith([RuntimeProviderConstants::PARENT_TEXT])]
    #[TestWith([RuntimeProviderConstants::IFACE_TEXT])]
    public function testStringClassConstantsRun(string $value): void
    {
        self::assertNotSame('', $value);
    }

    #[TestWith([RuntimeConstantEnum::DEFAULT_CASE])]
    public function testEnumCaseAliasConstantRuns(RuntimeConstantEnum $mode): void
    {
        self::assertSame(RuntimeConstantEnum::Active, $mode);
    }

    #[TestWith([RuntimeConstantEnum::Active])]
    public function testEnumInUnionRuns(RuntimeConstantEnum|string $mode): void
    {
        self::assertSame(RuntimeConstantEnum::Active, $mode);
    }
}
