<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\TestCase;

class ParentSetUpTest extends TestCase
{
    protected string $fromParent;

    protected function setUp(): void
    {
        $this->fromParent = 'parent';
    }
}

final class ChildSetUpTest extends ParentSetUpTest
{
    public function testReadsInheritedSetUp(): string
    {
        return $this->fromParent;
    }
}
