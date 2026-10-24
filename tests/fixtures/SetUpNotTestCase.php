<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

final class SetUpNotTestCase
{
    private string $ready;

    public function setUp(): void
    {
        $this->ready = 'ready';
    }

    public function testReadsReady(): string
    {
        return $this->ready;
    }
}
