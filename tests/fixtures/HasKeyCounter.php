<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\TestCase;

final class HasKeyCounterTest extends TestCase
{
    /**
     * The asserted key may be narrowed. A different optional key must stay uncertain.
     *
     * @param array{k?: string, other?: string} $arr
     */
    public function testOtherKey(array $arr): string
    {
        $this->assertArrayHasKey('k', $arr);

        return $arr['k'] . $arr['other'];
    }

    /**
     * A non-literal key must not mark the literal index as present.
     *
     * @param array{k?: string} $arr
     */
    public function testNonLiteralKey(array $arr, string $key): string
    {
        $this->assertArrayHasKey($key, $arr);

        return $arr['k'];
    }
}
