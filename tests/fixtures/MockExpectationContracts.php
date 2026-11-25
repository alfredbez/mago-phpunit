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

interface BillingGateway
{
    public function charge(int $cents): string;

    public function rate(): float;

    public function findCustomer(int $id): ?BillingCustomer;

    public function reset(): void;

    public function abort(): never;

    public function untyped();

    public function anything(): mixed;
}

class BillingCustomer
{
    public function name(): string
    {
        return 'customer';
    }
}

final class VipBillingCustomer extends BillingCustomer {}

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

    public function findCustomer(int $id): ?BillingCustomer
    {
        return null;
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

    /**
     * @param list<string> $dynamicStrings
     * @param list<ReturnStub> $dynamicStubs
     */
    public function testValidMockBuilderAndExpectations(
        string|null $maybeString,
        array $dynamicStrings,
        ReturnStub $opaqueReturnStub,
        ReturnCallback $opaqueReturnCallback,
        ReturnReference $returnReference,
        callable $untypedCallback,
        mixed $mixedValue,
        array $dynamicStubs,
        ReturnStub|int $stubOrInt,
    ): void {
        $builderMock = $this->getMockBuilder(ConcreteBillingGateway::class)
            ->onlyMethods(['charge', 'rate'])
            ->getMock();
        $builderMock->method('charge')->willReturn('tx_1');

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
            new ReturnCallback(fn(): string => 'ok'),
            new ReturnCallback(fn() => 'inferred'),
            new ReturnCallback('strtoupper'),
            new ReturnCallback([$this, 'stringCallbackHelper']),
            new ReturnCallback(fn(): never => throw new \RuntimeException('never')),
            new ExceptionStub(new \RuntimeException('boom2')),
            $opaqueReturnStub,
            $opaqueReturnCallback,
            $this->buildOpaqueReturnStub(123),
            CustomStubFactory::returnValue(123),
            $stubOrInt,
            new ReturnStub($mixedValue),
            new ReturnCallback($untypedCallback),
            new ReturnCallback(fn() => $mixedValue),
            new ReturnSelf(),
            new ReturnArgument(0),
            new ReturnValueMap([['ok']]),
            $returnReference,
            new ConsecutiveCalls(['ok']),
            ...[2 => new ReturnCallback(fn(): string => 'ok'), 1 => new ReturnStub('ok')],
            ...$dynamicStubs,
        );
        $stub->method('rate')->willReturnOnConsecutiveCalls(
            new ReturnStub(2),
            new ReturnCallback(fn(): int => 2),
        );
        $stub->method('findCustomer')->willReturnOnConsecutiveCalls(
            new ReturnStub(null),
            new ReturnStub(new VipBillingCustomer()),
            new ReturnCallback(function (): void {}),
        );
        $stub->method('abort')->willReturnOnConsecutiveCalls(
            $this->throwException(new \RuntimeException('boom')),
            new ExceptionStub(new \RuntimeException('boom2')),
            new ReturnCallback(fn(): never => throw new \RuntimeException('never-cb')),
        );
        $stub->method('reset')->willReturnOnConsecutiveCalls(null);

        $mock = $this->createMock(BillingGateway::class);
        $mock->expects(self::once())->method('charge')->with(100)->willReturn('receipt');
    }

    public function testInvalidMockBuilderOnlyMethods(): void
    {
        $this->getMockBuilder(ConcreteBillingGateway::class)
            ->onlyMethods(['charge', 'nonExistentBuilderMethod'])
            ->getMock();
    }

    public function testInvalidWillReturnTypes(): void
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
