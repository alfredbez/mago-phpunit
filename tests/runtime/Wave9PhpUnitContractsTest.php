<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Runtime;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\IncompatibleReturnValueException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

enum RuntimeUnitMode
{
    case Fast;
    case Slow;
}

enum RuntimeBackedMode: string
{
    case Active = 'active';
}

enum RuntimeIntMode: int
{
    case One = 1;
}

interface ConsecutiveStubTarget
{
    public function fetch(): string;

    public function abort(): never;
}

final class Wave9PhpUnitContractsTest extends TestCase
{
    public function testConsecutiveCallsAcceptsThrowExceptionStub(): void
    {
        $stub = $this->createStub(ConsecutiveStubTarget::class);
        $stub->method('fetch')->willReturnOnConsecutiveCalls(
            'first',
            $this->throwException(new RuntimeException('boom')),
        );

        self::assertSame('first', $stub->fetch());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $stub->fetch();
    }

    public function testConsecutiveCallsAcceptsThrowExceptionStubOnNeverReturn(): void
    {
        $stub = $this->createStub(ConsecutiveStubTarget::class);
        $stub->method('abort')->willReturnOnConsecutiveCalls(
            $this->throwException(new RuntimeException('never-boom')),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never-boom');
        $stub->abort();
    }

    public function testWillReturnRejectsDirectThrowExceptionStubAtRuntime(): void
    {
        $stub = $this->createStub(ConsecutiveStubTarget::class);

        $this->expectException(IncompatibleReturnValueException::class);
        $stub->method('fetch')->willReturn($this->throwException(new RuntimeException('not-unwrapped')));
    }

    #[TestWith([RuntimeUnitMode::Fast])]
    #[TestWith([RuntimeUnitMode::Slow])]
    public function testUnitEnumTestWithRuns(RuntimeUnitMode $mode): void
    {
        self::assertInstanceOf(RuntimeUnitMode::class, $mode);
    }

    #[TestWith([RuntimeBackedMode::Active])]
    public function testBackedEnumTestWithRuns(RuntimeBackedMode $mode): void
    {
        self::assertSame('active', $mode->value);
    }

    #[TestWith([RuntimeBackedMode::Active->value])]
    public function testBackedEnumValueTestWithRuns(string $value): void
    {
        self::assertSame('active', $value);
    }

    #[TestWith([RuntimeIntMode::One->value])]
    public function testIntBackedEnumValueTestWithRuns(int $code): void
    {
        self::assertSame(1, $code);
    }
}
