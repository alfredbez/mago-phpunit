<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use ArrayAccess;
use ArrayObject;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertContains;

const CONSTANT_ALIAS_MAP = [
    'DE' => 'DEU',
    'FR' => 'FRA',
];

final class AliasConstHolder
{
    public const ALIASES = [
        'DE' => 'DEU',
        'FR' => 'FRA',
    ];

    public const LIST = ['a', 'b'];

    public const EMPTY_LIST = [];

    public const DEFAULT = [
        'DE' => 'DEU',
    ];
}

final class AssertionNarrowingGuardsTest extends TestCase
{
    private const LOCAL_ALIASES = [
        'DE' => 'DEU',
        'FR' => 'FRA',
    ];

    /** @var array{k?: string} */
    private array $propMap = [];

    /** @var array{k?: string} */
    private static array $staticMap = [];

    /** @return 1|2 */
    public function testContainsSealedIntList(int $itemId): int
    {
        $this->assertContains($itemId, [1, 2]);
        $this->assertEquals($itemId, 1);

        return $itemId;
    }

    /** @return 'a'|'b' */
    public function testContainsSealedStringList(string $code): string
    {
        $this->assertContains($code, ['a', 'b']);

        return $code;
    }

    /** @return 1|2 */
    public function testContainsSealedListFunction(int $itemId): int
    {
        assertContains($itemId, [1, 2]);

        return $itemId;
    }

    /** @return 3 */
    public function testContainsSealedListStillRejectsImpossibleReturn(int $itemId): int
    {
        $this->assertContains($itemId, [1, 2]);

        return $itemId;
    }

    public function testContainsEmptyLiteralListStaysConservative(int $itemId): int
    {
        $this->assertContains($itemId, []);

        return $itemId;
    }

    /** @param list<int> $allowed */
    public function testContainsUnsealedListStillNarrows(int|string $needle, array $allowed): int
    {
        $this->assertContains($needle, $allowed);

        return $needle;
    }

    /** @return 1|2 */
    public function testContainsKeyedArrayNarrows(int|string $needle): int
    {
        $this->assertContains($needle, ['a' => 1, 'b' => 2]);

        return $needle;
    }

    /** @param array<string, string> $aliases */
    private function acceptAliases(array $aliases): int
    {
        return count($aliases);
    }

    /** @param list<string> $items */
    private function acceptStringList(array $items): int
    {
        return count($items);
    }

    public function testArrayNotHasKeyClassConstantDoesNotCorrupt(): int
    {
        $this->assertArrayNotHasKey('AQ', AliasConstHolder::ALIASES);
        $this->assertArrayNotHasKey('BL', AliasConstHolder::ALIASES);
        assertArrayNotHasKey('BQ', AliasConstHolder::ALIASES);
        $this->assertArrayNotHasKey('AQ', self::LOCAL_ALIASES);
        $this->assertArrayNotHasKey('BL', self::LOCAL_ALIASES);
        $this->assertArrayNotHasKey('AQ', CONSTANT_ALIAS_MAP);
        $this->assertArrayNotHasKey('BL', CONSTANT_ALIAS_MAP);

        $holder = new AliasConstHolder();
        $this->assertArrayNotHasKey('AQ', $holder::DEFAULT);
        $this->assertArrayNotHasKey('AQ', $holder::LIST);

        return $this->acceptAliases(AliasConstHolder::ALIASES)
            + $this->acceptAliases(self::LOCAL_ALIASES)
            + $this->acceptAliases(CONSTANT_ALIAS_MAP)
            + $this->acceptAliases($holder::DEFAULT)
            + $this->acceptStringList($holder::LIST);
    }

    public function testSiblingAssertionsOnClassConstantsDoNotCorrupt(): int
    {
        $this->assertCount(2, AliasConstHolder::LIST);
        $c1 = $this->acceptStringList(AliasConstHolder::LIST);

        $this->assertNotCount(0, AliasConstHolder::ALIASES);
        $c2 = $this->acceptAliases(AliasConstHolder::ALIASES);

        $this->assertNotEmpty(AliasConstHolder::ALIASES);
        $c3 = $this->acceptAliases(AliasConstHolder::ALIASES);

        $this->assertEmpty(AliasConstHolder::EMPTY_LIST);
        $c4 = $this->acceptStringList(AliasConstHolder::EMPTY_LIST);

        return $c1 + $c2 + $c3 + $c4;
    }

    /** @param array{k?: string} $local */
    public function testArrayNotHasKeyStillNarrowsVariablesAndProperties(array $local): void
    {
        $this->assertArrayNotHasKey('k', $local);
        $badLocal = $local['k'];

        $this->assertArrayNotHasKey('k', $this->propMap);
        $badProp = $this->propMap['k'];

        $this->assertArrayNotHasKey('k', self::$staticMap);
        $badStatic = self::$staticMap['k'];

        unset($badLocal, $badProp, $badStatic);
    }

    public function testArrayHasKeyOnIterableAfterAssertIsIterable(mixed $headers): mixed
    {
        $this->assertIsIterable($headers);
        $this->assertArrayHasKey('X-Test', $headers);
        $val = $headers['X-Test'];

        return $val;
    }

    public function testArrayHasKeyFunctionOnIterableAfterAssertIsIterable(mixed $headers): mixed
    {
        $this->assertIsIterable($headers);
        assertArrayHasKey('X-Test', $headers);
        $val = $headers['X-Test'];

        return $val;
    }

    public function testArrayHasKeyOnClassConstantDoesNotCorrupt(): int
    {
        $this->assertArrayHasKey('DE', AliasConstHolder::ALIASES);
        $this->assertArrayHasKey('FR', AliasConstHolder::ALIASES);

        return $this->acceptAliases(AliasConstHolder::ALIASES);
    }

    /** @param ArrayObject<array-key, mixed> $headers */
    public function testArrayHasKeyOnArrayObjectDoesNotCollapse(ArrayObject $headers): mixed
    {
        $this->assertArrayHasKey('X-Test', $headers);
        $val = $headers['X-Test'];

        return $val;
    }

    /** @param ArrayObject<array-key, mixed> $headers */
    public function testArrayHasKeyFunctionOnArrayObjectDoesNotCollapse(ArrayObject $headers): mixed
    {
        assertArrayHasKey('X-Test', $headers);
        $val = $headers['X-Test'];

        return $val;
    }

    /** @param iterable<string, string> $headers */
    public function testArrayHasKeyOnPureIterableDoesNotCollapse(iterable $headers): void
    {
        Assert::assertArrayHasKey('X-Test', $headers);
        foreach ($headers as $k => $v) {
            $this->assertNotSame('', $k . $v);
        }
    }

    /** @param array{k?: string}|array{k: int} $union */
    public function testArrayHasKeyOnArrayUnionStillNarrows(array $union): string|int
    {
        $this->assertArrayHasKey('k', $union);

        return $union['k'];
    }

    /**
     * @template T of array{k?: int}
     * @param T $templated
     */
    public function testArrayHasKeyOnTemplatedArrayStillNarrows(array $templated): int
    {
        $this->assertArrayHasKey('k', $templated);

        return $templated['k'];
    }

    public function testArrayNotHasKeyOnIterableDoesNotCollapse(mixed $headers): mixed
    {
        $this->assertIsIterable($headers);
        $this->assertArrayNotHasKey('X-Test', $headers);
        $val = $headers['X-Test'];

        return $val;
    }

    /** @param iterable<string, string> $headers */
    public function testArrayNotHasKeyOnPureIterableDoesNotCorrupt(iterable $headers): void
    {
        $this->assertArrayNotHasKey('X-Test', $headers);
        foreach ($headers as $k => $v) {
            $this->assertNotSame('', $k . $v);
        }
    }

    /** @param ArrayObject<array-key, mixed> $headers */
    public function testArrayNotHasKeyOnArrayObjectDoesNotCollapse(ArrayObject $headers): int
    {
        $this->assertArrayNotHasKey('X-Test', $headers);

        return $headers->count();
    }
}
