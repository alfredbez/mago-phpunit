<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\TestCase;

final class SetUpPositiveTest extends TestCase
{
    private string $ready;

    protected function setUp(): void
    {
        $this->ready = 'ready';
    }

    public function testReadsReady(): string
    {
        return $this->ready;
    }
}
