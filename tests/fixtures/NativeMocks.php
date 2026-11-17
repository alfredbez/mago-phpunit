<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class MockedDependency
{
    public function greet(string $name): string
    {
        return 'hello ' . $name;
    }
}

final class OtherDependency
{
    public function ping(): int
    {
        return 1;
    }
}

final class NativeMocksTest extends TestCase
{
    public function testCreateMockProvidesMockedAndMockObjectMethods(): MockedDependency&MockObject
    {
        $mock = $this->createMock(MockedDependency::class);
        $mock->greet('world');
        $mock->expects($this->once())->method('greet')->willReturn('hi');

        return $mock;
    }

    public function testCreateStubProvidesMockedAndStubMethods(): MockedDependency&Stub
    {
        $stub = $this->createStub(MockedDependency::class);
        $stub->greet('world');
        $stub->method('greet')->willReturn('hi');

        return $stub;
    }

    public function testCreateMockStillReportsUnknownMethod(): void
    {
        $this->createMock(MockedDependency::class)->missingMethod();
    }

    public function testCreateStubDoesNotExposeExpects(): void
    {
        $this->createStub(MockedDependency::class)->expects($this->once());
    }

    public function testCreateMockStillChecksDependencyClass(): void
    {
        $this->acceptOther($this->createMock(MockedDependency::class));
    }

    private function acceptOther(OtherDependency $dependency): int
    {
        return $dependency->ping();
    }
}
