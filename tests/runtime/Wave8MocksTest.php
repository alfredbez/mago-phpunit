<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\RuntimeTests;

use PHPUnit\Framework\MockObject\MethodCannotBeConfiguredException;
use PHPUnit\Framework\TestCase;

class Wave8MockTarget
{
    public function okay(): string { return 'real'; }
    final public function locked(): string { return 'locked'; }
    private function hidden(): void {}
    protected function guarded(): void {}
    public function __construct() {}
    public function __destruct() {}
}

class Wave8MagicMockTarget extends Wave8MockTarget
{
    public function __call(string $method, array $arguments): mixed { return null; }
}

trait Wave8ConcreteTrait
{
    public function concrete(): string { return 'trait'; }
    abstract public function abstractWork(): string;
}

trait Wave8ObjectTrait
{
    public function concrete(): string { return 'object'; }
}

final class Wave8MocksTest extends TestCase
{
    public function testPublicMethodCanBeConfigured(): void
    {
        $stub = $this->createStub(Wave8MockTarget::class);
        $stub->method('okay')->willReturn('configured');
        self::assertSame('configured', $stub->okay());
    }

    public function testNonConfigurableMethodsAreRejected(): void
    {
        foreach (['locked', 'hidden', 'guarded', '__construct', '__destruct', 'missing'] as $method) {
            $stub = $this->createStub(Wave8MockTarget::class);
            $caught = false;
            try {
                $stub->method($method);
            } catch (MethodCannotBeConfiguredException) {
                $caught = true;
            }
            self::assertTrue($caught, $method);
        }
    }

    public function testMagicCallsDoNotMakeFinalMethodsConfigurable(): void
    {
        $stub = $this->createStub(Wave8MagicMockTarget::class);
        $this->expectException(MethodCannotBeConfiguredException::class);
        $stub->method('locked');
    }

    public function testExplicitPartialMockSupportsProtectedMethods(): void
    {
        $mock = $this->getMockBuilder(Wave8MockTarget::class)->onlyMethods(['guarded'])->getMock();
        $mock->expects(self::never())->method('guarded');
        self::assertInstanceOf(Wave8MockTarget::class, $mock);
    }

    public function testLegacyTraitDoublesRetainConcreteBehavior(): void
    {
        if (!method_exists(TestCase::class, 'getMockForTrait')) {
            self::markTestSkipped('Trait factories were removed in PHPUnit 12.');
        }
        $mock = $this->getMockForTrait(Wave8ConcreteTrait::class);
        $mock->method('abstractWork')->willReturn('mock');
        self::assertSame('trait', $mock->concrete());
        self::assertSame('mock', $mock->abstractWork());
        $object = $this->getObjectForTrait(Wave8ObjectTrait::class);
        self::assertSame('object', $object->concrete());
    }
}
