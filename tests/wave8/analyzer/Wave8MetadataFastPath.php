<?php

declare(strict_types=1);

namespace Wave8\MetadataFastPath;

use PHPUnit\Framework\TestCase;

class CustomFixtureParent extends TestCase
{
    protected function setUp(): void {}
}

final class CustomFixtureChild extends CustomFixtureParent
{
    protected function setUp(): void {}
}

final class DirectFixture extends TestCase
{
    protected function setUp(): void {}

    /** @dataProvider */
    public function testIgnoredModernAnnotation(): void {}
}
