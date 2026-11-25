<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\MockObject\Stub\ConsecutiveCalls;
use PHPUnit\Framework\MockObject\Stub\Exception as ExceptionStub;
use PHPUnit\Framework\MockObject\Stub\ReturnArgument;
use PHPUnit\Framework\MockObject\Stub\ReturnCallback;
use PHPUnit\Framework\MockObject\Stub\ReturnReference;
use PHPUnit\Framework\MockObject\Stub\ReturnSelf;
use PHPUnit\Framework\MockObject\Stub\ReturnStub;
use PHPUnit\Framework\MockObject\Stub\ReturnValueMap;
use PHPUnit\Framework\TestCase;

interface ParentBillingGateway {}

interface SecondaryBillingGateway
{
    public function secondarySelf(): SecondaryBillingGateway;

    public function bothGateways(): BillingGateway&SecondaryBillingGateway;
}

interface BillingGateway extends ParentBillingGateway
{
    public function charge(int $cents): string;

    public function rate(): float;

    public function toRate(int $cents): float;

    public function findCustomer(int $id): ?BillingCustomer;

    public function promoteCustomer(VipBillingCustomer $customer): ?BillingCustomer;

    public function unrelatedCustomer(): BillingCustomer;

    public function fluentSelf(): self;

    public function fluentStatic(): static;

    public function fluentParent(): ParentBillingGateway;

    public function fluentUnion(): BillingGateway|string;

    public function fluentNullable(): ?BillingGateway;

    public function fluentObject(): object;

    public function echoText(string $text): string;

    public function pick(string|int ...$args): string;

    public function format(int $code, string ...$parts): string;

    public function defaultText(string $text = 'default'): string;

    public function nullableText(?string $text): ?string;

    public function requireText(?string $text): string;

    public function untypedInput($value): string;

    public function reset(): void;

    public function abort(): never;

    public function untyped();

    public function anything(): mixed;

    public function legacyArgs(): string;
}

class BillingCustomer
{
    public function name(): string
    {
        return 'customer';
    }
}

final class VipBillingCustomer extends BillingCustomer {}

class BaseFluentGateway
{
    public function baseSelf(): self
    {
        return $this;
    }

    public function baseStatic(): static
    {
        return $this;
    }
}

class ChildFluentGateway extends BaseFluentGateway
{
    public function asParent(): parent
    {
        return $this;
    }
}

class ConcreteBillingGateway implements BillingGateway
{
    public function charge(int $cents): string
    {
        return (string) $cents;
    }

    public function rate(): float
    {
        return 1.5;
    }

    public function toRate(int $cents): float
    {
        return (float) $cents;
    }

    public function findCustomer(int $id): ?BillingCustomer
    {
        return null;
    }

    public function promoteCustomer(VipBillingCustomer $customer): ?BillingCustomer
    {
        return $customer;
    }

    public function unrelatedCustomer(): BillingCustomer
    {
        return new BillingCustomer();
    }

    public function fluentSelf(): self
    {
        return $this;
    }

    public function fluentStatic(): static
    {
        return $this;
    }

    public function fluentParent(): ParentBillingGateway
    {
        return $this;
    }

    public function fluentUnion(): BillingGateway|string
    {
        return $this;
    }

    public function fluentNullable(): ?BillingGateway
    {
        return $this;
    }

    public function fluentObject(): object
    {
        return $this;
    }

    public function echoText(string $text): string
    {
        return $text;
    }

    public function pick(string|int ...$args): string
    {
        return (string) ($args[0] ?? '');
    }

    public function format(int $code, string ...$parts): string
    {
        return (string) $code . implode('', $parts);
    }

    public function defaultText(string $text = 'default'): string
    {
        return $text;
    }

    public function nullableText(?string $text): ?string
    {
        return $text;
    }

    public function requireText(?string $text): string
    {
        return $text ?? '';
    }

    public function untypedInput($value): string
    {
        return is_string($value) ? $value : '';
    }

    public function reset(): void {}

    public function abort(): never
    {
        throw new \RuntimeException('aborted');
    }

    public function untyped()
    {
        return 1;
    }

    public function anything(): mixed
    {
        return null;
    }

    public function legacyArgs(): string
    {
        return (string) (func_get_args()[0] ?? '');
    }
}

final class MockExpectationContractsTest extends TestCase
{
    public function stringCallbackHelper(): string
    {
        return 'helper';
    }

    public function intCallbackHelper(): int
    {
        return 42;
    }

    private function buildOpaqueReturnStub(int $seed): ReturnStub
    {
        return new ReturnStub((string) $seed);
    }

    private function mutateStubByRef(ReturnStub &$stub): void
    {
        $stub = new ReturnStub('by-ref-method');
    }

    private function mutateAndReturnString(ReturnStub &$stub): string
    {
        $stub = new ReturnStub('by-ref-inline');

        return 'first';
    }

    /**
     * @param list<string> $dynamicStrings
     * @param list<ReturnStub> $dynamicStubs
     * @param list<list<mixed>> $dynamicValueMap
     */
    public function testValidMockBuilderAndExpectations(
        string|null $maybeString,
        array $dynamicStrings,
        ReturnStub $opaqueReturnStub,
        ReturnCallback $opaqueReturnCallback,
        ReturnSelf $opaqueReturnSelf,
        ReturnArgument $opaqueReturnArgument,
        ReturnValueMap $opaqueReturnValueMap,
        ReturnReference $returnReference,
        ConsecutiveCalls $opaqueConsecutiveCalls,
        callable $untypedCallback,
        mixed $mixedValue,
        array $dynamicStubs,
        array $dynamicValueMap,
        ReturnStub|int $stubOrInt,
        int $dynamicIndex,
        bool $flag,
    ): void {
        $builderMock = $this->getMockBuilder(ConcreteBillingGateway::class)
            ->onlyMethods(['charge', 'rate'])
            ->getMock();
        $builderMock->method('charge')->willReturn('tx_1');

        $validLocalStub = new ReturnStub('local-ok');
        $aliasedValidStub = $validLocalStub;
        $validLocalCallback = new ReturnCallback(fn(): string => 'local-cb');
        $validLocalMapRows = [[100, 'mapped-ok']];
        $validLocalMap = new ReturnValueMap($validLocalMapRows);
        $validLocalConsecutive = new ConsecutiveCalls(['nested-ok', new ReturnStub('nested-ok-2')]);
        $validLocalSelf = new ReturnSelf();
        $validLocalArg = new ReturnArgument(0);

        $reassignedStub = new ReturnStub(321);
        $reassignedStub = new ReturnStub('reassigned-ok');

        $conditionalStub = new ReturnStub(321);
        if ($flag) {
            $conditionalStub = new ReturnStub('conditional-ok');
        }

        $capturedStub = new ReturnStub(321);
        $mutator = static function () use (&$capturedStub): void {
            $capturedStub = new ReturnStub('captured-ok');
        };
        $mutator();

        $helperLocalStub = $this->buildOpaqueReturnStub(123);
        $refHolder = 123;
        $localReturnReference = new ReturnReference($refHolder);
        $refHolder = 'mutated-ok';

        $stub = $this->createStub(BillingGateway::class);
        $stub->method('charge')->willReturn('ok', 'ok2');
        $stub->method('charge')->willReturn(...['a', 'b']);
        $stub->method('charge')->willReturn(...$dynamicStrings);
        $stub->method('charge')->willReturn($maybeString);
        $stub->method('rate')->willReturn(2);
        $stub->method('rate')->willReturn(2.5);
        $stub->method('findCustomer')->willReturn(null);
        $stub->method('findCustomer')->willReturn(new VipBillingCustomer());
        $stub->method('untyped')->willReturn(123);
        $stub->method('anything')->willReturn('any');
        $stub->method('charge')->willReturnOnConsecutiveCalls('a', 'b');
        $stub->method('charge')->willReturnOnConsecutiveCalls('a', $this->throwException(new \RuntimeException('boom')));
        $stub->method('charge')->willReturnOnConsecutiveCalls(
            new ReturnStub('valid'),
            $validLocalStub,
            $aliasedValidStub,
            new ReturnCallback(fn(): string => 'ok'),
            new ReturnCallback(fn() => 'inferred'),
            new ReturnCallback('strtoupper'),
            new ReturnCallback([$this, 'stringCallbackHelper']),
            new ReturnCallback(fn(): never => throw new \RuntimeException('never')),
            $validLocalCallback,
            new ExceptionStub(new \RuntimeException('boom2')),
            $opaqueReturnStub,
            $opaqueReturnCallback,
            $opaqueReturnArgument,
            $opaqueReturnValueMap,
            $opaqueConsecutiveCalls,
            $this->buildOpaqueReturnStub(123),
            $helperLocalStub,
            CustomStubFactory::returnValue(123),
            $reassignedStub,
            $conditionalStub,
            $capturedStub,
            $stubOrInt,
            new ReturnStub($mixedValue),
            new ReturnCallback($untypedCallback),
            new ReturnCallback(fn() => $mixedValue),
            new ReturnArgument($dynamicIndex),
            new ReturnValueMap([[100, 'ok']]),
            new ReturnValueMap([[100, $maybeString]]),
            new ReturnValueMap($dynamicValueMap),
            $validLocalMap,
            $returnReference,
            $localReturnReference,
            new ConsecutiveCalls(['ok']),
            $validLocalConsecutive,
            ...[2 => new ReturnCallback(fn(): string => 'ok'), 1 => new ReturnStub('ok')],
            ...$dynamicStubs,
        );
        $stub->method('fluentSelf')->willReturnOnConsecutiveCalls(new ReturnSelf(), $validLocalSelf, $opaqueReturnSelf);
        $stub->method('fluentStatic')->willReturnOnConsecutiveCalls(new ReturnSelf(), $validLocalSelf);
        $stub->method('fluentParent')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $stub->method('fluentUnion')->willReturnOnConsecutiveCalls(new ReturnSelf(), 'text');
        $stub->method('fluentNullable')->willReturnOnConsecutiveCalls(new ReturnSelf(), null);
        $stub->method('fluentObject')->willReturnOnConsecutiveCalls(new ReturnSelf());

        $childStub = $this->createStub(ChildFluentGateway::class);
        $childStub->method('baseSelf')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $childStub->method('baseStatic')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $childStub->method('asParent')->willReturnOnConsecutiveCalls(new ReturnSelf());

        $intersectionStub = $this->createStubForIntersectionOfInterfaces([BillingGateway::class, SecondaryBillingGateway::class]);
        $intersectionStub->method('fluentSelf')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $intersectionStub->method('secondarySelf')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $intersectionStub->method('bothGateways')->willReturnOnConsecutiveCalls(new ReturnSelf());

        $stub->method('echoText')->willReturnOnConsecutiveCalls(new ReturnArgument(0), $validLocalArg);
        $stub->method('format')->willReturnOnConsecutiveCalls(new ReturnArgument(1), new ReturnArgument(4));
        $stub->method('defaultText')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
        $stub->method('nullableText')->willReturnOnConsecutiveCalls(
            new ReturnArgument(0),
            new ReturnArgument(-1),
            new ReturnArgument(3),
            new ReturnValueMap([]),
            new ReturnValueMap([['no-match']]),
        );
        $stub->method('promoteCustomer')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
        $stub->method('toRate')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
        $stub->method('untypedInput')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
        $stub->method('pick')->willReturnOnConsecutiveCalls(new ReturnArgument($dynamicIndex));

        $stub->method('rate')->willReturnOnConsecutiveCalls(
            new ReturnStub(2),
            new ReturnCallback(fn(): int => 2),
        );
        $stub->method('findCustomer')->willReturnOnConsecutiveCalls(
            new ReturnStub(null),
            new ReturnStub(new VipBillingCustomer()),
            new ReturnCallback(function (): void {}),
        );

        $localExceptionStub = new ExceptionStub(new \RuntimeException('local-boom'));
        $localThrowHelper = $this->throwException(new \RuntimeException('local-helper-boom'));
        $localNeverCallback = new ReturnCallback(fn(): never => throw new \RuntimeException('local-never-cb'));
        $localThrowingConsecutive = new ConsecutiveCalls([
            new ExceptionStub(new \RuntimeException('nested-boom')),
            new ReturnCallback(fn(): never => throw new \RuntimeException('nested-never')),
        ]);
        $stub->method('abort')->willReturnOnConsecutiveCalls(
            $this->throwException(new \RuntimeException('boom')),
            new ExceptionStub(new \RuntimeException('boom2')),
            new ReturnCallback(fn(): never => throw new \RuntimeException('never-cb')),
            $localExceptionStub,
            $localThrowHelper,
            $localNeverCallback,
            $localThrowingConsecutive,
            new ConsecutiveCalls([]),
        );
        $stub->method('reset')->willReturnOnConsecutiveCalls(null);

        $mock = $this->createMock(BillingGateway::class);
        $mock->expects(self::once())->method('charge')->with(100)->willReturn('receipt');

        $fnMutatedStub = new ReturnStub(123);
        replaceStubByRef($fnMutatedStub);
        $methodMutatedStub = new ReturnStub(123);
        $this->mutateStubByRef($methodMutatedStub);
        $staticMutatedStub = new ReturnStub(123);
        StaticStubMutator::swap($staticMutatedStub);
        $closureMutatedStub = new ReturnStub(123);
        $swapClosure = static function (ReturnStub &$target): void {
            $target = new ReturnStub('by-ref-closure');
        };
        $swapClosure($closureMutatedStub);
        $ctorMutatedStub = new ReturnStub(123);
        new StubByRefMutator($ctorMutatedStub);
        $anonCtorMutatedStub = new ReturnStub(123);
        new class ($anonCtorMutatedStub) {
            public function __construct(ReturnStub &$target)
            {
                $target = new ReturnStub('by-ref-anon-ctor');
            }
        };
        $stub->method('charge')->willReturnOnConsecutiveCalls(
            $fnMutatedStub,
            $methodMutatedStub,
            $staticMutatedStub,
            $closureMutatedStub,
            $ctorMutatedStub,
            $anonCtorMutatedStub,
        );

        $inlineMutatedStub = new ReturnStub(123);
        $stub->method('charge')->willReturnOnConsecutiveCalls(
            $this->mutateAndReturnString($inlineMutatedStub),
            $inlineMutatedStub,
        );

        $loopMutatedStub = new ReturnStub(123);
        for ($i = 0; $i < 2; ++$i) {
            if ($i === 1) {
                $stub->method('charge')->willReturnOnConsecutiveCalls($loopMutatedStub);
            }
            replaceStubByRef($loopMutatedStub);
        }

        $shiftedStubs = [new ReturnStub(123), new ReturnStub('ok')];
        array_shift($shiftedStubs);
        $stub->method('charge')->willReturnOnConsecutiveCalls(...$shiftedStubs);

        $mutatedMapRows = [[100, 123]];
        array_pop($mutatedMapRows);
        array_push($mutatedMapRows, [100, 'ok']);
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ReturnValueMap($mutatedMapRows));

        $mutatedStack = [123, 'ok'];
        array_shift($mutatedStack);
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ConsecutiveCalls($mutatedStack));

        $mutatedArgIndex = -1;
        setZeroByRef($mutatedArgIndex);
        $stub->method('echoText')->willReturnOnConsecutiveCalls(new ReturnArgument($mutatedArgIndex));

        $incrementedArgIndex = -1;
        if ($flag) {
            ++$incrementedArgIndex;
        }
        $stub->method('echoText')->willReturnOnConsecutiveCalls(new ReturnArgument($incrementedArgIndex));

        $stub->method('legacyArgs')->willReturnOnConsecutiveCalls(new ReturnValueMap([['arg1', 'ok']]));
    }

    /**
     * @param array<string, ReturnStub> $vars
     */
    public function testValidExtractMutation(array $vars): void
    {
        $stub = $this->createStub(BillingGateway::class);
        $extractedStub = new ReturnStub(123);
        extract($vars);
        $stub->method('charge')->willReturnOnConsecutiveCalls($extractedStub);
    }

    public function testValidEvalMutation(string $code): void
    {
        $stub = $this->createStub(BillingGateway::class);
        $evalStub = new ReturnStub(123);
        eval($code);
        $stub->method('charge')->willReturnOnConsecutiveCalls($evalStub);
    }

    public function testValidIncludeMutation(string $path): void
    {
        $stub = $this->createStub(BillingGateway::class);
        $includedStub = new ReturnStub(123);
        include $path;
        $stub->method('charge')->willReturnOnConsecutiveCalls($includedStub);
    }

    public function testInvalidMockBuilderOnlyMethods(): void
    {
        $this->getMockBuilder(ConcreteBillingGateway::class)
            ->onlyMethods(['charge', 'nonExistentBuilderMethod'])
            ->getMock();
    }

    public function testInvalidWillReturnTypes(ReturnSelf $opaqueReturnSelf): void
    {
        $stub = $this->createStub(BillingGateway::class);
        $stub->method('charge')->willReturn(123);
        $stub->method('charge')->willReturn(...[123]);
        $stub->method(constraint: 'charge')->willReturn(123);
        $stub->method('charge')->willReturn(null);
        $stub->method('findCustomer')->willReturn('not-a-customer');
        $stub->method('reset')->willReturn(true);
        $stub->method('charge')->willReturnOnConsecutiveCalls('ok', 42);
        $stub->method('charge')->willReturnOnConsecutiveCalls(
            new ReturnStub('valid'),
            new ReturnStub(123),
            new ReturnCallback(fn(): string => 'ok'),
            new ReturnCallback(fn(): array => []),
            new ReturnCallback(fn() => 99),
            new ReturnCallback(function (): void {}),
            new ReturnCallback('strlen'),
            new ReturnCallback([$this, 'intCallbackHelper']),
            ...[new ReturnStub('ok'), new ReturnStub(77)],
        );
        $stub->method('charge')->willReturn($this->throwException(new \RuntimeException('direct-stub-invalid')));
        $stub->method('abort')->willReturnOnConsecutiveCalls(new ReturnStub(null));

        $badLocalStub = new ReturnStub(321);
        $aliasedBadStub = $badLocalStub;
        $badLocalCallback = new ReturnCallback(fn(): int => 42);
        $badLocalList = [new ReturnStub(88)];
        $badLocalNever = new ReturnStub(null);
        $stub->method('charge')->willReturnOnConsecutiveCalls($badLocalStub);
        $stub->method('echoText')->willReturnOnConsecutiveCalls($badLocalStub);
        $stub->method('charge')->willReturnOnConsecutiveCalls($aliasedBadStub);
        $stub->method('charge')->willReturnOnConsecutiveCalls($badLocalCallback);
        $stub->method('charge')->willReturnOnConsecutiveCalls(...$badLocalList);
        $stub->method('abort')->willReturnOnConsecutiveCalls($badLocalNever);

        $badLocalSelf = new ReturnSelf();
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ReturnSelf());
        $stub->method('charge')->willReturnOnConsecutiveCalls($badLocalSelf);
        $stub->method('charge')->willReturnOnConsecutiveCalls($opaqueReturnSelf);
        $stub->method('unrelatedCustomer')->willReturnOnConsecutiveCalls(new ReturnSelf());

        $intersectionStub = $this->createStubForIntersectionOfInterfaces([BillingGateway::class, SecondaryBillingGateway::class]);
        $intersectionStub->method('charge')->willReturnOnConsecutiveCalls(new ReturnSelf());

        $badLocalArg = new ReturnArgument(0);
        $badLocalIdx = 0;
        $stub->method('pick')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
        $stub->method('pick')->willReturnOnConsecutiveCalls($badLocalArg);
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ReturnArgument($badLocalIdx));
        $stub->method('requireText')->willReturnOnConsecutiveCalls(new ReturnArgument(0));
        $stub->method('echoText')->willReturnOnConsecutiveCalls(new ReturnArgument(-1));
        $stub->method('echoText')->willReturnOnConsecutiveCalls(new ReturnArgument(2));
        $stub->method('format')->willReturnOnConsecutiveCalls(new ReturnArgument(0));

        $badMapRows = [[100, 123]];
        $badLocalMap = new ReturnValueMap($badMapRows);
        $secondBadLocalMap = new ReturnValueMap($badMapRows);
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ReturnValueMap([[100, 123]]));
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ReturnValueMap([[100, 'ok'], [200, 456]]));
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ReturnValueMap([]));
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ReturnValueMap([['ok']]));
        $stub->method('legacyArgs')->willReturnOnConsecutiveCalls(new ReturnValueMap([['arg1', 123]]));
        $stub->method('charge')->willReturnOnConsecutiveCalls($badLocalMap);
        $stub->method('echoText')->willReturnOnConsecutiveCalls($secondBadLocalMap);
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ConsecutiveCalls([123]));
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ConsecutiveCalls([new ReturnStub(123)]));
        $stub->method('charge')->willReturnOnConsecutiveCalls(new ConsecutiveCalls([new ReturnSelf()]));

        $mock = $this->createMock(BillingGateway::class);
        $mock->expects(self::once())->method('rate')->willReturn('not-a-float');
    }
}

final class CustomStubFactory
{
    public static function returnValue(int $id): ReturnStub
    {
        return new ReturnStub((string) $id);
    }
}

function replaceStubByRef(ReturnStub &$stub): void
{
    $stub = new ReturnStub('by-ref-fn');
}

function setZeroByRef(int &$index): void
{
    $index = 0;
}

final class StaticStubMutator
{
    public static function swap(ReturnStub &$stub): void
    {
        $stub = new ReturnStub('by-ref-static');
    }
}

final class StubByRefMutator
{
    public function __construct(ReturnStub &$stub)
    {
        $stub = new ReturnStub('by-ref-ctor');
    }
}
