<?php

declare(strict_types=1);

namespace Wave8\MetadataFastPath;

use PHPUnit\Framework\Attributes\RequiresPhp as RuntimePhp;
use PHPUnit\Framework\TestCase;

#[RuntimePhp('8.2')]
final class AliasedRequirement extends TestCase
{
    public function testExample(): void {}
}
