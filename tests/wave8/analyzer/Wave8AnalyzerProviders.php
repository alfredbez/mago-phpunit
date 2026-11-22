<?php

declare(strict_types=1);

namespace Wave8\Analyzer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderCompletenessTest extends TestCase
{
    public static function emptyRows(): array { return []; }
    public static function firstRows(): array { return ['same' => [1], 'first' => [2]]; }
    public static function secondRows(): array { return ['same' => [3]]; }
    public static function distinctRows(): array { return ['other' => [4]]; }
    public static function emptyKey(): array { return ['' => [1]]; }
    public static function testProvider(): array { return [[1]]; }
    #[Test]
    public static function rowsAsTest(): array { return [[1]]; }

    #[DataProvider('rowsAsTest')]
    public function testAttributedProvider(int $n): void {}

    public static function emptyGenerator(): \Generator { yield from []; return [99]; }
    public static function maybeRows(): \Generator { if (random_int(0, 1)) { yield [1]; } }
    public static function nestedEmpty(): array { $ignored = static function (): \Generator { yield from []; }; return [[1]]; }

    #[DataProvider('emptyGenerator')]
    public function testEmptyGenerator(int $n): void {}

    #[DataProvider('maybeRows')]
    public function testUnknownGenerator(int $n): void {}

    #[DataProvider('nestedEmpty')]
    public function testNestedEmpty(int $n): void {}

    #[DataProvider('emptyRows', skipWhenEmpty: true)]
    public function testSkipEmpty(int $n): void {}

    #[DataProvider('emptyRows')]
    public function testEmpty(int $n): void {}

    #[DataProvider('emptyRows')]
    #[DataProvider('firstRows')]
    public function testNonEmptyCombined(int $n): void {}

    #[DataProvider('firstRows')]
    #[DataProvider('secondRows')]
    public function testDuplicate(int $n): void {}

    #[DataProvider('firstRows')]
    #[DataProvider('distinctRows')]
    public function testDistinct(int $n): void {}

    #[DataProvider('testProvider')]
    public function testProviderIsTest(int $n): void {}

    #[TestWith([1], 'same')]
    #[TestWith([2], 'same')]
    public function testDuplicateWith(int $n): void {}

    #[TestWith([1], 'first')]
    #[TestWith([2], 'other')]
    public function testDistinctWith(int $n): void {}

    #[DataProvider('firstRows')]
    #[TestWith([1])]
    public function testMixed(int $n): void {}

    #[DataProvider('emptyKey')]
    public function testEmptyKey(int $n): void {}

    /** @testWith [1, "valid"] */
    public function testDocValid(int $id, string $name): void {}

    /** @testWith ["wrong"] */
    public function testDocInvalid(int $id, string $name): void {}

    /** @testWith {invalid */
    public function testDocJson(int $id): void {}

    /**
     * @testWith [1, "first"]
     *           [2, "second"]
     */
    public function testDocMultiline(int $id, string $name): void {}

    /**
     * @testWith [1, "first"]
     *           ["wrong", "second"]
     */
    public function testDocMultilineInvalid(int $id, string $name): void {}

    /** @testWith ["ignored"] */
    #[TestWith([1])]
    public function testDocIgnored(int $id): void {}
}
