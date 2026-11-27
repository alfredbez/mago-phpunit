<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertContains;

final class AssertionNarrowingGuardsRuntimeTest extends TestCase
{
    private const ALIASES = [
        'DE' => 'DEU',
        'FR' => 'FRA',
    ];

    public function testAssertContainsSealedListsAndKeyedArrays(): void
    {
        $intId = 1;
        $this->assertContains($intId, [1, 2]);
        assertContains($intId, [1, 2]);

        $code = 'b';
        $this->assertContains($code, ['a', 'b']);

        $unsealed = [10, 20];
        $this->assertContains(10, $unsealed);

        $keyed = ['a' => 1, 'b' => 2];
        $this->assertContains(2, $keyed);
    }

    public function testAssertContainsRejectsMissingElement(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertContains(3, [1, 2]);
    }

    public function testAssertContainsEmptyListFailsAtRuntime(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertContains(1, []);
    }

    public function testAssertArrayNotHasKeyOnClassConstantAndLocalVariable(): void
    {
        $this->assertArrayNotHasKey('AQ', self::ALIASES);
        $this->assertArrayNotHasKey('BL', self::ALIASES);
        assertArrayNotHasKey('BQ', self::ALIASES);

        $local = ['present' => 'yes'];
        $this->assertArrayNotHasKey('absent', $local);
        $this->assertSame('yes', $local['present']);
    }

    public function testAssertArrayHasKeyOnArrayObjectIterableAndArray(): void
    {
        $headers = new ArrayObject(['X-Test' => 'ok']);
        $this->assertIsIterable($headers);
        $this->assertArrayHasKey('X-Test', $headers);
        assertArrayHasKey('X-Test', $headers);
        $this->assertSame('ok', $headers['X-Test']);

        $plain = ['k' => 'value'];
        $this->assertArrayHasKey('k', $plain);
        $this->assertSame('value', $plain['k']);
    }
}
