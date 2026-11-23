<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Tests\Runtime;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class Wave8MutableAssertionsRuntimeTest extends TestCase
{
    public bool $eventFired = false;

    private function checkCache(bool $broken): void
    {
        $count = 0;
        $callback = static function () use (&$count): void { ++$count; };
        $callback();
        self::assertSame(1, $count);
        if ($broken) {
            $callback();
        }
        self::assertSame(1, $count);
    }

    public function testReferenceCallbackCanChangeAssertedCounter(): void
    {
        $this->checkCache(false);
        $this->expectException(AssertionFailedError::class);
        $this->checkCache(true);
    }

    private function checkDispatcher(bool $broken): void
    {
        $callback = function (): void { $this->eventFired = true; };
        $this->eventFired = false;
        if ($broken) {
            $callback();
        }
        self::assertFalse($this->eventFired);
    }

    public function testPropertyCallbackCanChangeAssertedFlag(): void
    {
        $this->checkDispatcher(false);
        $this->expectException(AssertionFailedError::class);
        $this->checkDispatcher(true);
    }

    public function testClosedHandleAssertionCanFail(): void
    {
        $handle = fopen('php://memory', 'r+');
        self::assertIsResource($handle);
        fclose($handle);
        $this->expectException(AssertionFailedError::class);
        self::assertIsNotClosedResource($handle);
    }
}
