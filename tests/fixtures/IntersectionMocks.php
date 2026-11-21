<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use Countable;
use PHPUnit\Framework\TestCase;
use stdClass;

interface IntersectionClock
{
    public function now(): int;
}

interface IntersectionLogger
{
    public function log(string $message): void;
}

function consumeIntersectionClock(IntersectionClock $clock): int
{
    return $clock->now();
}

function consumeIntersectionLogger(IntersectionLogger $logger): void
{
    $logger->log('tick');
}

final class IntersectionMocksTest extends TestCase
{
    public function testIntersectionMockHasAllListedInterfaces(): void
    {
        $mock = $this->createMockForIntersectionOfInterfaces([
            IntersectionClock::class,
            IntersectionLogger::class,
        ]);
        $mock->method('now')->willReturn(100);
        $mock->expects($this->once())->method('log');
        $mock->log('tick');
        self::assertSame(100, $mock->now());
        self::assertSame(100, consumeIntersectionClock($mock));
    }

    public function testIntersectionStubHasAllListedInterfaces(): void
    {
        $stub = self::createStubForIntersectionOfInterfaces([
            IntersectionClock::class,
            IntersectionLogger::class,
        ]);
        $stub->method('now')->willReturn(200);
        $stub->log('tick');
        self::assertSame(200, $stub->now());
        consumeIntersectionLogger($stub);
    }

    public function testThreeInterfacesAndVariableList(): void
    {
        $interfaces = [
            IntersectionClock::class,
            IntersectionLogger::class,
            Countable::class,
        ];
        $mock = $this->createMockForIntersectionOfInterfaces($interfaces);
        $mock->method('now')->willReturn(1);
        $mock->method('count')->willReturn(2);
        $mock->log('tick');
        self::assertSame(1, $mock->now());
        self::assertSame(2, $mock->count());
    }

    public function testUnknownMethodsAndStubExpectsReportErrors(): void
    {
        $mock = $this->createMockForIntersectionOfInterfaces([
            IntersectionClock::class,
            IntersectionLogger::class,
        ]);
        $stub = $this->createStubForIntersectionOfInterfaces([
            IntersectionClock::class,
            IntersectionLogger::class,
        ]);
        $mock->missingInterfaceMethod();
        $stub->expects($this->once());
    }

    /**
     * @param list<class-string> $openList
     */
    public function testFallbackToBaseDoubleForInvalidInputs(array $openList): void
    {
        $this->createMockForIntersectionOfInterfaces($openList)->now();
        $this->createMockForIntersectionOfInterfaces([IntersectionClock::class])->now();
        $this->createMockForIntersectionOfInterfaces([IntersectionClock::class, IntersectionClock::class])->now();
        $this->createMockForIntersectionOfInterfaces([IntersectionClock::class, stdClass::class])->now();
    }
}
