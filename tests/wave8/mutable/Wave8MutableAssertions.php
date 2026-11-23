<?php

declare(strict_types=1);

namespace Wave8\Mutable;

use PHPUnit\Framework\TestCase;

final class CallbackCache
{
    private bool $ran = false;

    public function __construct(private \Closure $callback) {}

    public function run(): void
    {
        if (!$this->ran) {
            ($this->callback)();
            $this->ran = true;
        }
    }
}

final class Dispatcher
{
    public function __construct(private \Closure $callback) {}
    public function dispatch(bool $fire): void { if ($fire) { ($this->callback)(); } }
}

final class MutableAssertionsTest extends TestCase
{
    public bool $eventFired = false;

    public function referenceCallback(string $stable): void
    {
        $count = 0;
        $cache = new CallbackCache(static function () use (&$count): void { ++$count; });
        $cache->run();
        self::assertSame(1, $count);
        $cache->run();
        self::assertSame(1, $count); // callback can update count
        self::assertIsString($stable); // stable parameter
        $count = 5;
        self::assertSame(5, $count); // fresh captured assignment
    }

    public function propertyCallback(bool $fire): void
    {
        $dispatcher = new Dispatcher(function (): void { $this->eventFired = true; });
        $this->eventFired = false;
        $dispatcher->dispatch($fire);
        self::assertFalse($this->eventFired); // callback can update property
        $this->eventFired = true;
        self::assertTrue($this->eventFired); // fresh property assignment
    }

    public function referenceOnExpectedSide(): void
    {
        $value = 0;
        $callback = static function () use (&$value): void { ++$value; };
        self::assertSame(0, $value); // initial reference fact
        $callback();
        self::assertSame($value, 0); // expected counter can change
        $fixed = 3;
        self::assertSame($fixed, 3); // stable expected variable
    }

    public function propertyOnExpectedSide(bool $fire): void
    {
        $dispatcher = new Dispatcher(function (): void { $this->eventFired = true; });
        $this->eventFired = false;
        self::assertSame(false, $this->eventFired); // initial property fact
        $dispatcher->dispatch($fire);
        self::assertSame($this->eventFired, false); // expected property can change
        self::assertSame('fixed', 'fixed'); // stable expected literal
    }

    public function closedHandle(): void
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return;
        }
        self::assertIsResource($handle); // untouched open handle
        fclose($handle);
        self::assertIsNotClosedResource($handle); // closed handle assertion can fail
    }

    public function unrelatedCall(string $value): void
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return;
        }
        strlen($value);
        self::assertIsResource($handle); // unrelated call preserves handle
    }

    public function literalsRemainKnown(): void
    {
        self::assertTrue(true); // literal
        self::assertIsString('fixed'); // literal string
    }

    public function unrelatedReference(int $count): void
    {
        $other = 0;
        $callback = static function () use (&$other): void { ++$other; };
        $callback();
        self::assertIsInt($count); // unrelated reference
    }
}
