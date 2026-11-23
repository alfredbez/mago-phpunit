<?php

declare(strict_types=1);

namespace Wave8\TopLevel {
    use function PHPUnit\Framework\assertSame;
    use function PHPUnit\Framework\assertIsResource;
    use function PHPUnit\Framework\assertIsNotClosedResource;

    $value = 0;
    $callback = static function () use (&$value): void { ++$value; };
    assertSame(0, $value);
    $callback();
    assertSame($value, 0); // changed top-level expected
    assertSame(0, $value); // changed top-level actual
    $value = 5;
    assertSame($value, 5); // fresh top-level value

    final class State { public bool $fired = false; }
    $state = new State();
    $event = static function () use ($state): void { $state->fired = true; };
    $state->fired = false;
    $event();
    assertSame(false, $state->fired); // changed top-level property
    $state->fired = false;
    assertSame(false, $state->fired); // fresh top-level property

    $handle = fopen('php://memory', 'r+');
    if ($handle !== false) {
        assertIsResource($handle); // stable top-level resource
        fclose($handle);
        assertIsNotClosedResource($handle); // changed top-level resource
    }

    $stable = 7;
    function unrelatedScope(int $stable): void {
        $inner = static function () use (&$stable): void { ++$stable; };
        $inner();
    }
    $noop = static fn () => null;
    $noop();
    assertSame(7, $stable); // unrelated nested reference
    assertSame('fixed', 'fixed'); // stable top-level literal

    $captured = 0;
    $mutator = static function () use (&$captured): void { ++$captured; };
    $check = static function () use (&$captured, $mutator): void {
        assertSame(0, $captured);
        $mutator();
        assertSame($captured, 0); // changed own closure capture
        $captured = 9;
        assertSame(9, $captured); // fresh own closure capture
    };
    $check();

    $shared = 0;
    $sharedCallback = static function () use (&$shared): void { ++$shared; };
    assertSame(0, $shared);
}

namespace Wave8\OtherTopLevel {
    use function PHPUnit\Framework\assertSame;
    $sharedCallback();
    assertSame($shared, 0); // changed across namespace blocks
    $shared = 4;
    assertSame(4, $shared); // fresh across namespace blocks
}
