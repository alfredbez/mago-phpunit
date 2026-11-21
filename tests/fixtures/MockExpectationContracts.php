<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\TestCase;

interface BillingGateway
{
    public function charge(int $cents): string;

    public function rate(): float;

    public function findCustomer(int $id): ?BillingCustomer;

    public function reset(): void;

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
    /** @param list<string> $dynamicStrings */
    public function testValidMockBuilderAndExpectations(string|null $maybeString, array $dynamicStrings): void
    {
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

        $mock = $this->createMock(BillingGateway::class);
        $mock->expects(self::once())->method('rate')->willReturn('not-a-float');
    }
}
