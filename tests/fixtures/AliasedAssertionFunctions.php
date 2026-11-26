<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\TestCase;

use function count;
use function PHPUnit\Framework\assertNotNull as check;
use function PHPUnit\Framework\assertSame as verify;

final class AliasedAssertionFunctionsTest extends TestCase
{
    /** @param list<string> $items */
    public function testAliasedAssertionFunctionsStillReport(array $items): void
    {
        check('already-non-null');
        verify(2, count($items));
    }
}
