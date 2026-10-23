<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\TestCase;

final class SetUpHelperTest extends TestCase
{
    private string $ready;

    protected function setUp(): void
    {
        $this->boot();
    }

    private function boot(): void
    {
        $this->ready = 'ready';
    }

    public function testReadsReady(): string
    {
        return $this->ready;
    }
}
