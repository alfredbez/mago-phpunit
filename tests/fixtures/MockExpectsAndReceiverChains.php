<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\MockObject\Stub\ConsecutiveCalls;
use PHPUnit\Framework\TestCase;

trait ChainTargetTrait
{
    public function fromTrait(): int
    {
        return 7;
    }
}

class ChainTarget
{
    use ChainTargetTrait;

    public function __construct() {}

    public function __destruct() {}

    public function getInt(): int
    {
        return 1;
    }

    public function acceptAndReturn(string $input): int
    {
        return strlen($input);
    }

    final public function finalMethod(): int
    {
        return 2;
    }

    private function privateMethod(): void {}

    protected function protectedMethod(): void {}

    public function untypedFactory(): mixed
    {
        return null;
    }
}

interface ChainFirstInterface
{
    public function firstCall(): int;
}

interface ChainSecondInterface
{
    public function secondCall(): string;
}

class ChainMagicTarget
{
    /** @param array<array-key, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}

final class MockExpectsAndReceiverChainsTest extends TestCase
{
    private MockObject&ChainTarget $mockProp;

    protected function setUp(): void
    {
        $this->mockProp = $this->createMock(ChainTarget::class);
    }

    /** @return MockObject&ChainTarget */
    private function makeTargetMock(): MockObject
    {
        return $this->createMock(ChainTarget::class);
    }

    /** @return Stub&ChainTarget */
    private function makeTargetStub(): Stub
    {
        return $this->createStub(ChainTarget::class);
    }

    /** @param non-empty-string $dynamicName */
    public function testValidChainsAndExpectsMethods(string $dynamicName, MockObject $untypedMock): void
    {
        $this->createMock(ChainTarget::class)->method('getInt')->willReturn(1);
        $this->createStub(ChainTarget::class)->method('getInt')->willReturn(2);
        $this->makeTargetMock()->method('getInt')->willReturn(3);
        $this->makeTargetStub()->method('getInt')->willReturn(4);
        $this->createMock(ChainTarget::class)
            ->expects($this->once())
            ->method('acceptAndReturn')
            ->with('abc')
            ->willReturn(5);
        $this->createMock(ChainTarget::class)
            ->expects($this->exactly(2))
            ->method('getInt')
            ->willReturnOnConsecutiveCalls(new ConsecutiveCalls([1, 2]));

        $mock = $this->createMock(ChainTarget::class);
        $mock->expects($this->once())->method('getInt')->willReturn(10);
        $mock->expects($this->once())->method('fromTrait')->willReturn(11);
        $mock->expects($this->once())->method($dynamicName);
        $mock->expects($this->once())->method($this->anything());

        $magic = $this->createMock(ChainMagicTarget::class);
        $magic->expects($this->once())->method('dynamicMagicMethod')->willReturn('ok');

        $intersection = $this->createMockForIntersectionOfInterfaces([
            ChainFirstInterface::class,
            ChainSecondInterface::class,
        ]);
        $intersection->expects($this->once())->method('firstCall')->willReturn(1);
        $intersection->expects($this->once())->method('secondCall')->willReturn('ok');

        $untypedMock->expects($this->once())->method('anythingOnUntyped')->willReturn(true);

        // Conservative: untyped intermediate factory must not walk past onto $mock.
        $mock->untypedFactory()->method('getInt')->willReturn('untouched');
    }

    public function testInvalidMethodCallReceiversWillReturn(): void
    {
        $this->createMock(ChainTarget::class)->method('getInt')->willReturn('bad-create-mock');
        $this->createStub(ChainTarget::class)->method('getInt')->willReturn('bad-create-stub');
        $this->makeTargetMock()->method('getInt')->willReturn('bad-helper-mock');
        $this->createMock(ChainTarget::class)
            ->expects($this->once())
            ->method('getInt')
            ->willReturn('bad-create-mock-expects');
        $this->createMock(ChainTarget::class)
            ->expects($this->once())
            ->method('getInt')
            ->willReturnOnConsecutiveCalls(new ConsecutiveCalls(['bad-nested']));
    }

    public function testInvalidExpectsMethodTargets(): void
    {
        $mock = $this->createMock(ChainTarget::class);
        $mock->expects($this->once())->method('doesNotExist')->willReturn(1);
        $mock->expects($this->once())->method('finalMethod')->willReturn(1);
        $mock->expects($this->once())->method('privateMethod');
        $mock->expects($this->once())->method('protectedMethod');
        $mock->expects($this->once())->method('__construct');
        $mock->expects($this->once())->method('__destruct');
        $mock->expects($this->once())->id('step1')->method('missingAfterId');

        $this->mockProp->expects($this->once())->method('missingOnProp');
        $this->createMock(ChainTarget::class)->expects($this->once())->method('missingOnFactory');
        $this->makeTargetMock()->expects($this->once())->method('missingOnHelper');

        $intersection = $this->createMockForIntersectionOfInterfaces([
            ChainFirstInterface::class,
            ChainSecondInterface::class,
        ]);
        $intersection->expects($this->once())->method('missingOnIntersection');
    }
}
