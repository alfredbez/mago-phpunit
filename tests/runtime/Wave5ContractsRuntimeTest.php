<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\RuntimeTests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\MockObject\CannotUseOnlyMethodsException;
use PHPUnit\Framework\MockObject\IncompatibleReturnValueException;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertJson;
use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertStringEndsWith;
use function PHPUnit\Framework\assertStringStartsWith;

interface Wave5RuntimeGateway
{
    public function charge(int $cents): string;

    public function rate(): float;

    public function reset(): void;
}

class Wave5RuntimeGatewayImpl implements Wave5RuntimeGateway
{
    public function charge(int $cents): string
    {
        return (string) $cents;
    }

    public function rate(): float
    {
        return 1.5;
    }

    public function reset(): void {}
}

final class Wave5ContractsRuntimeTest extends TestCase
{
    public function testStringAffixAndJsonAssertionsRejectEmptyStrings(): void
    {
        assertStringStartsWith('a', 'abc');
        assertStringEndsWith('c', 'abc');
        assertJson('{"ok":true}');
        assertJson('0');

        foreach ([
            static fn () => self::assertStringStartsWith('a', ''),
            static fn () => self::assertStringEndsWith('c', ''),
            static fn () => self::assertJson(''),
        ] as $case) {
            try {
                $case();
                self::fail('Expected AssertionFailedError for empty string');
            } catch (AssertionFailedError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIntegerBoundAssertions(): void
    {
        assertGreaterThan(0, 1);
        assertGreaterThanOrEqual(1, 1);
        assertLessThan(0, -1);
        assertLessThanOrEqual(-1, -1);
        self::assertGreaterThan(0, 1.5);
    }

    public function testMockBuilderOnlyMethodsRejectsUnknownMethod(): void
    {
        $this->expectException(CannotUseOnlyMethodsException::class);
        $this->getMockBuilder(Wave5RuntimeGatewayImpl::class)
            ->onlyMethods(['doesNotExist'])
            ->getMock();
    }

    public function testWillReturnRejectsIncompatibleTypeAtRuntime(): void
    {
        $stub = $this->createStub(Wave5RuntimeGateway::class);
        $stub->method('rate')->willReturn(2.5);
        self::assertSame(2.5, $stub->rate());

        $this->expectException(IncompatibleReturnValueException::class);
        $stub->method('charge')->willReturn(123);
    }
}
