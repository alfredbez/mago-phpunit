<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\TestCase;

final class SetUpCounterTest extends TestCase
{
    private string $ready;

    private string $missing;

    protected function setUp(): void
    {
        $this->ready = 'ready';
    }

    public function testReadsBoth(): string
    {
        return $this->ready . $this->missing;
    }
}
