<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version;

final class ProviderVariadicNamedTest extends TestCase
{
    /** @return list<array<string, int|string>> */
    public static function namedVariadicRows(): array
    {
        return [
            ['n' => 1, 'first' => 'a', 'second' => 'b'],
            ['n' => 2, 'rest' => 'c'],
        ];
    }

    #[DataProvider('namedVariadicRows')]
    public function testNamedVariadicRow(int $n, string ...$rest): void
    {
        $namedUnpacking = version_compare(Version::id(), '11.0.0', '>=');
        if ($n === 1) {
            self::assertSame($namedUnpacking ? ['first' => 'a', 'second' => 'b'] : ['a', 'b'], $rest);
        } else {
            self::assertSame($namedUnpacking ? ['rest' => 'c'] : ['c'], $rest);
        }
    }
}
