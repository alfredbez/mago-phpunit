<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Runtime;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\Stub\ConsecutiveCalls;
use PHPUnit\Framework\MockObject\Stub\Exception as ExceptionStub;
use PHPUnit\Framework\MockObject\Stub\ReturnArgument;
use PHPUnit\Framework\MockObject\Stub\ReturnCallback;
use PHPUnit\Framework\MockObject\Stub\ReturnReference;
use PHPUnit\Framework\MockObject\Stub\ReturnSelf;
use PHPUnit\Framework\MockObject\Stub\ReturnStub;
use PHPUnit\Framework\MockObject\Stub\ReturnValueMap;
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

interface ConsecutivePayloadParent {}

interface ConsecutivePayloadTarget extends ConsecutivePayloadParent
{
    public function result(): string;

    public function nullable(): ?string;

    public function abort(): never;

    public function fluentSelf(): self;

    public function fluentStatic(): static;

    public function fluentParent(): ConsecutivePayloadParent;

    public function fluentUnion(): ConsecutivePayloadTarget|string;

    public function echoText(string $text): string;

    public function pick(string|int ...$args): string;

    public function format(int $code, string ...$parts): string;

    public function toFloat(int $value): float;

    public function lookup(string $key): string;

    public function legacyArgs(): string;
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

    public function testConsecutiveCallsAcceptsValidLocalStubsAndNestedConsecutiveCalls(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);
        $localStub = new ReturnStub('local-ok');
        $localCallback = new ReturnCallback(static fn(): string => 'local-cb');
        $localNested = new ConsecutiveCalls(['nested-ok']);

        $stub->method('result')->willReturnOnConsecutiveCalls($localStub, $localCallback, $localNested);

        self::assertSame('local-ok', $stub->result());
        self::assertSame('local-cb', $stub->result());
        self::assertSame('nested-ok', $stub->result());
    }

    public function testConsecutiveCallsAcceptsCompatibleReturnSelfShapes(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);
        $stub->method('fluentSelf')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $stub->method('fluentStatic')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $stub->method('fluentParent')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $stub->method('fluentUnion')->willReturnOnConsecutiveCalls(new ReturnSelf());

        self::assertSame($stub, $stub->fluentSelf());
        self::assertSame($stub, $stub->fluentStatic());
        self::assertSame($stub, $stub->fluentParent());
        self::assertSame($stub, $stub->fluentUnion());
    }

    public function testConsecutiveCallsAcceptsCompatibleReturnArgumentShapes(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);
        $stub->method('echoText')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
        $stub->method('format')->willReturnOnConsecutiveCalls(new ReturnArgument(1), new ReturnArgument(2));
        $stub->method('toFloat')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
        $stub->method('nullable')->willReturnOnConsecutiveCalls(new ReturnArgument(-1), new ReturnArgument(5));

        self::assertSame('hello', $stub->echoText('hello'));
        self::assertSame('first', $stub->format(1, 'first', 'second'));
        self::assertSame('second', $stub->format(1, 'first', 'second'));
        self::assertSame(4.0, $stub->toFloat(4));
        self::assertNull($stub->nullable());
        self::assertNull($stub->nullable());
    }

    public function testConsecutiveCallsAcceptsCompatibleReturnValueMapAndMutatedReturnReference(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);
        $stub->method('lookup')->willReturnOnConsecutiveCalls(new ReturnValueMap([['a', 'mapped']]));
        $stub->method('nullable')->willReturnOnConsecutiveCalls(new ReturnValueMap([]));

        $holder = 123;
        $stub->method('result')->willReturnOnConsecutiveCalls(new ReturnReference($holder));
        $holder = 'mutated-before-call';

        self::assertSame('mapped', $stub->lookup('a'));
        self::assertNull($stub->nullable());
        self::assertSame('mutated-before-call', $stub->result());
    }

    public function testConsecutiveCallsAcceptsByRefMutatedLocalsAndExtraArgValueMap(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);

        $replaceByRef = static function (ReturnStub &$target): void {
            $target = new ReturnStub('mutated-stub');
        };
        $localStub = new ReturnStub(123);
        $replaceByRef($localStub);

        $shifted = [new ReturnStub(123), new ReturnStub('shifted-ok')];
        array_shift($shifted);

        $rows = [['a', 123]];
        array_pop($rows);
        array_push($rows, ['a', 'row-ok']);

        $stack = [123, 'stack-ok'];
        array_shift($stack);

        $setZero = static function (int &$index): void {
            $index = 0;
        };
        $argIndex = -1;
        $setZero($argIndex);

        $stub->method('result')->willReturnOnConsecutiveCalls(
            $localStub,
            new ConsecutiveCalls($stack),
            ...$shifted,
        );
        $stub->method('lookup')->willReturnOnConsecutiveCalls(new ReturnValueMap($rows));
        $stub->method('echoText')->willReturnOnConsecutiveCalls(new ReturnArgument($argIndex));
        $stub->method('legacyArgs')->willReturnOnConsecutiveCalls(new ReturnValueMap([['arg1', 'extra-ok']]));

        self::assertSame('mutated-stub', $stub->result());
        self::assertSame('stack-ok', $stub->result());
        self::assertSame('shifted-ok', $stub->result());
        self::assertSame('row-ok', $stub->lookup('a'));
        self::assertSame('echoed', $stub->echoText('echoed'));
        self::assertSame('extra-ok', $stub->legacyArgs('arg1'));
    }

    public function testReturnValueMapTooFewArgumentsRowCannotMatchAndThrowsTypeError(): void
    {
        $stub = $this->createStub(ConsecutivePayloadTarget::class);
        $stub->method('lookup')->willReturnOnConsecutiveCalls(new ReturnValueMap([['ok']]));

        $this->expectException(TypeError::class);
        $stub->lookup('a');
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
        $localException = new ExceptionStub(new RuntimeException('never-boom'));
        $localNeverCallback = new ReturnCallback(static fn(): never => throw new RuntimeException('never-cb'));
        $stub->method('abort')->willReturnOnConsecutiveCalls($localException, $localNeverCallback);

        try {
            $stub->abort();
            self::fail('Expected RuntimeException from local ExceptionStub.');
        } catch (RuntimeException $exception) {
            self::assertSame('never-boom', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never-cb');
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
