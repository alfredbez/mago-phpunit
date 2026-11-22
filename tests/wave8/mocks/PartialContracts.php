<?php

declare(strict_types=1);
namespace Wave8Mocks;
use PHPUnit\Framework\TestCase;
final class PartialContracts extends TestCase
{
    public function testPartial(): void
    {
        $partial = $this->getMockBuilder(Subject::class)->onlyMethods(['guarded'])->getMock();
        $partial->method('guarded'); // partial-protected
        $partial->method('dynamicAdded'); // partial-unknown
        $partial->method('locked'); // partial-final
        $partial->method('hidden'); // partial-private
    }
}
