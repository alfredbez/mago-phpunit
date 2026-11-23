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

    public function testExpectedCounterCanChangeAfterCallback(): void
    {
        $value = 0;
        $callback = static function () use (&$value): void { ++$value; };
        self::assertSame(0, $value);
        $callback();
        $this->expectException(AssertionFailedError::class);
        self::assertSame($value, 0);
    }

    public function testExpectedPropertyCanChangeAfterCallback(): void
    {
        $callback = function (): void { $this->eventFired = true; };
        $this->eventFired = false;
        self::assertSame(false, $this->eventFired);
        $callback();
        $this->expectException(AssertionFailedError::class);
        self::assertSame($this->eventFired, false);
    }

    public function testIncludedStatementsShareCallbackStateAcrossNamespaces(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'phpunit-callback-');
        file_put_contents($file, <<<'PHP'
<?php
namespace CallbackSource {
    use function PHPUnit\Framework\assertSame;
    $value = 0;
    $callback = static function () use (&$value): void { ++$value; };
    assertSame(0, $value);
}
namespace CallbackDestination {
    use function PHPUnit\Framework\assertSame;
    $callback();
    assertSame($value, 0);
}
PHP);
        $this->expectException(AssertionFailedError::class);
        try {
            require $file;
        } finally {
            unlink($file);
        }
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
