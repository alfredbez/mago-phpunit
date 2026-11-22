<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures\Wave8;

use ArrayObject;
use PHPUnit\Framework\Assert;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEmpty;
use function PHPUnit\Framework\assertIsList;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotCount;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertObjectHasProperty;

enum Colour { case Red; case Blue; }
final class Concrete { public string $name = 'kept'; }

function objectProperty(object $value): mixed
{
    Assert::assertObjectHasProperty('name', $value);
    return $value->name;
}

function objectPropertyFunction(mixed $value): mixed
{
    assertObjectHasProperty('name', $value); // @wave8 mixed-argument
    return $value->name;
}

function objectOtherProperty(object $value): mixed
{
    Assert::assertObjectHasProperty('name', $value);
    return $value->other; // @wave8 ambiguous-object-property-access
}

function concreteIsPreserved(Concrete $value): string
{
    assertObjectHasProperty('name', $value);
    return $value->name;
}

/**
 * @param list<string> $value
 * @return 2
 */
function exactCount(array $value): int
{
    Assert::assertCount(2, $value);
    return count($value);
}

/**
 * @param list<string> $value
 * @return 3
 */
function exactCountCounter(array $value): int
{
    assertCount(2, $value);
    return count($value); // @wave8 invalid-return-statement
}

/**
 * @param list<string> $value
 * @return non-empty-list<string>
 */
function notCount(array $value): array
{
    Assert::assertNotCount(0, $value);
    return $value;
}

/**
 * @param list<string> $value
 * @return non-empty-list<string>
 */
function notCountFunction(array $value): array
{
    assertNotCount(0, $value);
    return $value;
}

/**
 * @param list<string> $value
 * @return non-empty-list<string>
 */
function notCountDoesNotInventNonEmpty(array $value): array
{
    assertNotCount(2, $value);
    return $value; // @wave8 invalid-return-statement
}

/**
 * @param list<string> $value
 * @return 2
 */
function dynamicCountDoesNotInventExact(int $expected, array $value): int
{
    Assert::assertCount($expected, $value);
    return count($value); // @wave8 invalid-return-statement
}

function notNull(?string $value): string
{
    Assert::assertNotSame(null, $value);
    return $value;
}

/**
 * @param 1|2 $value
 * @return 2
 */
function notInt(int $value): int
{
    assertNotSame(1, $value);
    return $value;
}

/** @return false */
function notBool(bool $value): bool
{
    Assert::assertNotSame(true, $value);
    return $value;
}

/**
 * @param 'a'|'b' $value
 * @return 'b'
 */
function notString(string $value): string
{
    assertNotSame('a', $value);
    return $value;
}

/**
 * @param Colour::Red|Colour::Blue $value
 * @return Colour::Blue
 */
function notEnum(Colour $value): Colour
{
    Assert::assertNotSame(Colour::Red, $value);
    return $value;
}

/**
 * @param 1|2 $value
 * @return 2
 */
function broadExpectedDoesNotExclude(int $expected, int $value): int
{
    assertNotSame($expected, $value);
    return $value; // @wave8 invalid-return-statement
}

/** @param list<string> $haystack */
function containsType(int|string $needle, array $haystack): string
{
    Assert::assertContains($needle, $haystack);
    return $needle;
}

/** @param array<string, int> $haystack */
function containsTypeFunction(mixed $needle, array $haystack): int
{
    assertContains($needle, $haystack);
    return $needle;
}

/** @param list<int|string> $haystack */
function containsKeepsUnion(int|string $needle, array $haystack): string
{
    assertContains($needle, $haystack);
    return $needle; // @wave8 invalid-return-statement
}

/**
 * @param 'a'|'b'|'c' $needle
 * @return 'c'
 */
function notContainsLiteral(string $needle): string
{
    Assert::assertNotContains($needle, ['a', 'b']);
    return $needle;
}

/**
 * @param 1|2|3 $needle
 * @return 3
 */
function notContainsLiteralFunction(int $needle): int
{
    assertNotContains($needle, [1, 2]);
    return $needle;
}

/**
 * @param list<string> $haystack
 * @param 'a'|'b' $needle
 * @return 'b'
 */
function notContainsDynamicDoesNotExclude(string $needle, array $haystack): string
{
    Assert::assertNotContains($needle, $haystack);
    return $needle; // @wave8 invalid-return-statement
}

/**
 * @param array{0?: 'a'} $haystack
 * @param 'a'|'b' $needle
 * @return 'b'
 */
function notContainsOptionalDoesNotExclude(string $needle, array $haystack): string
{
    assertNotContains($needle, $haystack);
    return $needle; // @wave8 invalid-return-statement
}

/**
 * @param array{0: 'a'|'b'} $haystack
 * @param 'a'|'b'|'c' $needle
 * @return 'c'
 */
function notContainsUnionElementDoesNotExclude(string $needle, array $haystack): string
{
    assertNotContains($needle, $haystack);
    return $needle; // @wave8 invalid-return-statement
}

/**
 * @param array<string, int> $value
 * @return array{}
 */
function emptyArray(array $value): array
{
    Assert::assertEmpty($value);
    return $value;
}

/** @return 0 */
function emptyInt(int $value): int
{
    assertEmpty($value);
    return $value;
}

/**
 * @param ArrayObject<array-key, mixed> $value
 * @return ArrayObject<array-key, mixed>
 */
function emptyCountableKeepsObject(ArrayObject $value): ArrayObject
{
    Assert::assertEmpty($value);
    return $value; // @wave8-native-empty-object
}

/** @return ''|'0' */
function emptyStringKeepsZero(string $value): string
{
    assertEmpty($value);
    return $value;
}

/**
 * @param array<array-key, string> $value
 * @return list<string>
 */
function listType(array $value): array
{
    Assert::assertIsList($value);
    return $value;
}

/** @return list<mixed> */
function listTypeFunction(mixed $value): array
{
    assertIsList($value);
    return $value;
}

/** @return '' */
function emptyStringDoesNotDiscardZero(string $value): string
{
    assertEmpty($value);
    return $value; // @wave8 invalid-return-statement @wave8-native-empty-string
}

/**
 * @param list<list<string>> $value
 * @return 2
 */
function recursiveCountIsNotTopLevel(array $value): int
{
    Assert::assertCount(2, $value);
    return count($value, COUNT_RECURSIVE); // @wave8 invalid-return-statement
}

/**
 * @param list<string> $value
 * @return 2
 */
function dynamicCountModeIsNotTopLevel(array $value, int $mode): int
{
    Assert::assertCount(2, $value);
    return count($value, $mode); // @wave8 invalid-return-statement
}

/**
 * @param array{present: string, optional?: int} $value
 * @return 1
 */
function optionalShapeCountIsUnknown(array $value): int
{
    return count($value); // @wave8 invalid-return-statement
}

/** @param object{kept: string, ...} $value */
function objectShapeKeepsExistingProperty(object $value): string
{
    Assert::assertObjectHasProperty('added', $value);
    return $value->kept;
}

function dynamicPropertyDoesNotInventName(object $value, string $property): mixed
{
    assertObjectHasProperty($property, $value);
    return $value->name; // @wave8 ambiguous-object-property-access
}

/**
 * @param array{0: 'a'}|array{0: 'a', 1: 'b'} $haystack
 * @param 'a'|'b'|'c' $needle
 * @return 'b'|'c'
 */
function notContainsUnionHaystacks(string $needle, array $haystack): string
{
    assertNotContains($needle, $haystack);
    return $needle;
}

/**
 * @param array{0: 'a'}|array{0: 'b'} $haystack
 * @param 'a'|'b'|'c' $needle
 * @return 'c'
 */
function notContainsDisjointHaystacks(string $needle, array $haystack): string
{
    Assert::assertNotContains($needle, $haystack);
    return $needle; // @wave8 invalid-return-statement
}

/**
 * @param list<string> $value
 * @return 2
 */
function normalModeCount(array $value): int
{
    assertCount(2, $value);
    return count($value, COUNT_NORMAL);
}

/**
 * @param list<string> $value
 * @return 0
 */
function zeroCount(array $value): int
{
    assertCount(0, $value);
    return count($value);
}

/**
 * @param list<string> $value
 * @return non-empty-list<string>
 */
function negativeCountDoesNotNarrow(array $value): array
{
    Assert::assertCount(-1, $value);
    return $value; // @wave8 invalid-return-statement
}

/** @return false */
function emptyBool(bool $value): bool
{
    Assert::assertEmpty($value);
    return $value;
}

/** @return ''|'0'|0 */
function emptyArrayKey(int|string $value): int|string
{
    assertEmpty($value);
    return $value;
}
