<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class DataProviderExternalCrossFileTest extends TestCase
{
    #[DataProviderExternal(ExternalCrossFileProvider::class, 'validRows')]
    public function testCrossFileValid(int $n, string $label): void
    {
    }

    #[DataProviderExternal(ExternalCrossFileProvider::class, 'invalidRows')]
    public function testCrossFileInvalid(int $n, string $label): void
    {
    }
}
