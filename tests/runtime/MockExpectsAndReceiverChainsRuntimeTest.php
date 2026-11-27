<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\IncompatibleReturnValueException;
use PHPUnit\Framework\MockObject\MethodCannotBeConfiguredException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

trait RuntimeChainTrait
{
    public function fromTrait(): int
    {
        return 7;
    }
}

class RuntimeChainSubject
{
    use RuntimeChainTrait;

    public function __construct() {}

    public function __destruct() {}

    public function getInt(): int
    {
        return 1;
    }

    final public function finalMethod(): int
    {
        return 2;
    }

    private function privateMethod(): void {}

    protected function protectedMethod(): void {}
}

#[AllowMockObjectsWithoutExpectations]
final class MockExpectsAndReceiverChainsRuntimeTest extends TestCase
{
    /** @return MockObject&RuntimeChainSubject */
    private function makeSubjectMock(): MockObject
    {
        return $this->createMock(RuntimeChainSubject::class);
    }

    public function testCreateMockMethodWillReturnRejectsIncompatibleType(): void
    {
        $mock = $this->createMock(RuntimeChainSubject::class);
        $caught = false;
        try {
            $mock->method('getInt')->willReturn('bad');
        } catch (IncompatibleReturnValueException) {
            $caught = true;
        }
        $this->assertTrue($caught);
    }

    public function testCreateStubMethodWillReturnRejectsIncompatibleType(): void
    {
        $this->expectException(IncompatibleReturnValueException::class);
        $this->createStub(RuntimeChainSubject::class)->method('getInt')->willReturn('bad');
    }

    public function testHelperMockMethodWillReturnRejectsIncompatibleType(): void
    {
        $caught = false;
        try {
            $this->makeSubjectMock()->method('getInt')->willReturn('bad');
        } catch (IncompatibleReturnValueException) {
            $caught = true;
        }
        $this->assertTrue($caught);
    }

    public function testCreateMockExpectsMethodWillReturnRejectsIncompatibleType(): void
    {
        $mock = $this->createMock(RuntimeChainSubject::class);
        $caught = false;
        try {
            $mock->expects($this->any())->method('getInt')->willReturn('bad');
        } catch (IncompatibleReturnValueException) {
            $caught = true;
        }
        $this->assertTrue($caught);
    }

    public function testExpectsMethodRejectsNonConfigurableMethods(): void
    {
        foreach (['doesNotExist', 'finalMethod', 'privateMethod', 'protectedMethod', '__construct', '__destruct'] as $method) {
            $mock = $this->createMock(RuntimeChainSubject::class);
            $caught = false;
            try {
                $mock->expects($this->once())->method($method);
            } catch (MethodCannotBeConfiguredException) {
                $caught = true;
            } finally {
                if (method_exists($mock, '__phpunit_unsetInvocationMocker')) {
                    $mock->__phpunit_unsetInvocationMocker();
                }
            }
            $this->assertTrue($caught, $method);
        }
    }

    public function testExpectsMethodAllowsValidAndTraitMethods(): void
    {
        $mock = $this->createMock(RuntimeChainSubject::class);
        $mock->expects($this->once())->method('getInt')->willReturn(42);
        $mock->expects($this->once())->method('fromTrait')->willReturn(9);

        $this->assertSame(42, $mock->getInt());
        $this->assertSame(9, $mock->fromTrait());
    }
}
