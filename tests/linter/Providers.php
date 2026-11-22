<?php

declare(strict_types=1);

namespace Wave8Linter;

use PHPUnit\Framework\Attributes\DataProvider as Rows;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

abstract class ProviderParent extends TestCase
{
    public static function inheritedRows(): array
    {
        return [[1]];
    }
}

final class Providers extends ProviderParent
{
    #[Rows('myROWS')]
    public function testAttribute(int $value): void {}

    /** @dataProvider INHERITEDROWS */
    public function testDocblock(int $value): void {}

    #[Rows('myRows')]
    #[Test]
    public function testAlreadyAttributed(int $value): void {}

    public function testCollision(): void {}

    public function collision(): void {}

    protected function testNotPublic(): void {}

    public static function myRows(): array
    {
        return [[1]];
    }
}
