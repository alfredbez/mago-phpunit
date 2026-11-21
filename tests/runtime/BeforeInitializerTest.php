<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Runtime;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before as Initialize;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

abstract class BeforeRuntimeBase extends TestCase
{
    protected string $inherited;

    #[Initialize]
    protected function prepareInherited(): void
    {
        $this->inherited = 'inherited';
    }
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Before {}

final class OrdinaryBefore
{
    public string $ready;

    #[Initialize]
    public function prepare(): void
    {
        $this->ready = 'ready';
    }
}

final class BeforeInitializerTest extends BeforeRuntimeBase
{
    private string $ready;
    private string $second;
    private string $missing;
    private string $late;
    private string $unrelated;

    #[Initialize]
    protected function prepare(): void
    {
        $this->assign();
    }

    private function assign(): void
    {
        $this->ready = 'ready';
    }

    #[Initialize]
    protected function prepareSecond(): void
    {
        $this->second = 'second';
    }

    #[Initialize]
    protected function prepareMissing(): void {}

    #[After]
    protected function finish(): void
    {
        $this->late = 'late';
    }

    #[Before]
    protected function unrelated(): void
    {
        $this->unrelated = 'unrelated';
    }

    public function testBeforeHooksInitializeProperties(): void
    {
        self::assertSame('ready', $this->ready);
        self::assertSame('second', $this->second);
        self::assertSame('inherited', $this->inherited);
    }

    public function testControlsRemainUninitializedDuringTest(): void
    {
        foreach (['missing', 'late', 'unrelated'] as $property) {
            self::assertFalse((new ReflectionProperty(self::class, $property))->isInitialized($this));
        }
    }

    public function testOrdinaryObjectRequiresExplicitCall(): void
    {
        $ordinary = new OrdinaryBefore();
        self::assertFalse((new ReflectionProperty($ordinary, 'ready'))->isInitialized($ordinary));
        $ordinary->prepare();
        self::assertSame('ready', $ordinary->ready);
    }
}
