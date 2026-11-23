<?php

declare(strict_types=1);

namespace Wave8\GlobalMutable;

use function PHPUnit\Framework\assertIsString;
use function PHPUnit\Framework\assertIsResource;
use function PHPUnit\Framework\assertIsNotClosedResource;
use function PHPUnit\Framework\assertSame;

function actualReference(string $stable): void
{
    $count = 0;
    $callback = static function () use (&$count): void { ++$count; };
    assertSame(0, $count);
    $callback();
    assertSame(0, $count); // mutable actual reference
    assertIsString($stable); // stable global parameter
    $count = 5;
    assertSame(5, $count); // fresh global reference
}

function expectedReference(): void
{
    $count = 0;
    $callback = static function () use (&$count): void { ++$count; };
    assertSame(0, $count);
    $callback();
    assertSame($count, 0); // mutable expected reference
    assertSame('fixed', 'fixed'); // stable global literal
}

final class GlobalState { public bool $fired = false; }
final class GlobalDispatcher
{
    public function __construct(private \Closure $callback) {}
    public function run(): void { ($this->callback)(); }
}

function mutableProperty(GlobalState $state): void
{
    $dispatcher = new GlobalDispatcher(static function () use ($state): void { $state->fired = true; });
    $state->fired = false;
    $dispatcher->run();
    assertSame(false, $state->fired); // mutable actual property
    $state->fired = false;
    assertSame(false, $state->fired); // fresh global property
    $dispatcher->run();
    assertSame($state->fired, false); // mutable expected property
}

function closureChecks(): \Closure
{
    return static function (string $stable): void {
        $count = 0;
        $callback = static function () use (&$count): void { ++$count; };
        assertSame(0, $count);
        $callback();
        assertSame($count, 0); // mutable closure reference
        assertIsString($stable); // stable closure parameter
    };
}

function closureActualChecks(): \Closure
{
    return static function (): void {
        $count = 0;
        $callback = static function () use (&$count): void { ++$count; };
        assertSame(0, $count);
        $callback();
        assertSame(0, $count); // mutable closure actual reference
        $count = 5;
        assertSame($count, 5); // fresh closure expected reference
    };
}

function closedHandle(): void
{
    $handle = fopen('php://memory', 'r+');
    if ($handle === false) {
        return;
    }
    assertIsResource($handle); // stable global resource
    fclose($handle);
    assertIsNotClosedResource($handle); // mutable global resource
}
