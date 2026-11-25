<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\TestWithJson;
use PHPUnit\Framework\TestCase;

enum TestWithUnitMode
{
    case Fast;
    case Slow;
}

enum TestWithStringMode: string
{
    case Active = 'active';
    case Paused = 'paused';

    public const DEFAULT_CODE = 200;
    public const DEFAULT_CASE = self::Active;
}

enum TestWithIntMode: int
{
    case One = 1;
}

interface TestWithInterfaceConstants
{
    public const IFACE_TEXT = 'iface';
}

trait TestWithTraitConstants
{
    public const TRAIT_CODE = 7;
}

class TestWithParentConstants
{
    public const PARENT_TEXT = 'parent';
}

final class ExistingProviderConstants extends TestWithParentConstants implements TestWithInterfaceConstants
{
    use TestWithTraitConstants;

    public const int ANSWER = 42;
    public const string TEXT = 'valid';
    public const ALIAS = self::ANSWER;
    /** @var positive-int */
    public const EXPR_POSITIVE = 10 + 5;
    public const CYCLE_A = self::CYCLE_B;
    public const CYCLE_B = self::CYCLE_A;
    public const array VALID_ROW = ['valid'];
    public const array INVALID_ROW = [42];
}

final class TestWithAttributesTest extends TestCase
{
    public function testSeed(): string
    {
        return 'seed';
    }

    #[TestWith([1, 'ok'])]
    #[TestWith([2])]
    #[TestWith([3, 'ok', true])]
    #[TestWith(['wrong', 'ok'])]
    public function testPositionalTestWith(int $n, string $label): void
    {
    }

    #[TestWith(['n' => 1, 'label' => 'ok'])]
    #[TestWith(['n' => 2])]
    #[TestWith(['n' => 3, 'label' => 'ok', 'extra' => true])]
    #[TestWith(['n' => 'wrong', 'label' => 'ok'])]
    public function testNamedTestWith(int $n, string $label): void
    {
    }

    #[TestWith([1])]
    #[Depends('testSeed')]
    public function testTestWithAndDependency(int $n, string $seed): void
    {
    }

    #[TestWithJson('[1, "ok"]')]
    #[TestWithJson('[2]')]
    #[TestWithJson('[3, "ok", true]')]
    #[TestWithJson('["wrong", "ok"]')]
    #[TestWithJson('{"n": 4, "label": "ok"}')]
    #[TestWithJson('{"n": 5}')]
    #[TestWithJson('{invalid')]
    #[TestWithJson('42')]
    public function testTestWithJson(int $n, string $label): void
    {
    }

    #[TestWith([TestWithUnitMode::Fast])]
    #[TestWith([TestWithUnitMode::Slow])]
    public function testUnitEnumTestWith(TestWithUnitMode $mode): void
    {
    }

    #[TestWith([TestWithStringMode::Active])]
    #[TestWith([TestWithStringMode::Paused])]
    public function testBackedEnumTestWith(TestWithStringMode $mode): void
    {
    }

    #[TestWith([TestWithStringMode::Active->value])]
    public function testBackedEnumValueTestWith(string $modeValue): void
    {
    }

    #[TestWith([TestWithIntMode::One->value])]
    public function testIntBackedEnumValueTestWith(int $modeCode): void
    {
    }

    /** @param 1 $modeCode */
    #[TestWith([TestWithIntMode::One->value])]
    public function testIntBackedEnumLiteralValueTestWith($modeCode): void
    {
    }

    /** @param 2 $modeCode */
    #[TestWith([TestWithIntMode::One->value])]
    public function testIntBackedEnumWrongLiteralValueTestWith($modeCode): void
    {
    }

    #[TestWith([TestWithStringMode::Active])]
    public function testWrongEnumTestWith(TestWithUnitMode $mode): void
    {
    }

    #[TestWith([TestWithUnitMode::Fast])]
    public function testEnumForStringTestWith(string $label): void
    {
    }

    #[TestWith([TestWithIntMode::One->value])]
    public function testIntBackedEnumValueForStringTestWith(string $label): void
    {
    }

    #[TestWith([ExistingProviderConstants::ANSWER])]
    public function testValidIntClassConstantTestWith(int $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::TEXT])]
    public function testValidStringClassConstantTestWith(string $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::ALIAS])]
    public function testValidAliasClassConstantTestWith(int $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::PARENT_TEXT])]
    public function testValidParentClassConstantTestWith(string $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::IFACE_TEXT])]
    public function testValidInterfaceClassConstantTestWith(string $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::TRAIT_CODE])]
    public function testValidTraitClassConstantTestWith(int $value): void
    {
    }

    /** @param positive-int $value */
    #[TestWith([ExistingProviderConstants::EXPR_POSITIVE])]
    public function testValidDocNarrowedClassConstantTestWith(int $value): void
    {
    }

    #[TestWith([TestWithUnitMode::Fast])]
    public function testValidEnumInUnionTestWith(TestWithUnitMode|string $mode): void
    {
    }

    #[TestWith([TestWithStringMode::DEFAULT_CODE])]
    public function testValidEnumClassConstantTestWith(int $code): void
    {
    }

    #[TestWith([TestWithStringMode::DEFAULT_CASE])]
    public function testValidEnumCaseAliasConstantTestWith(TestWithStringMode $mode): void
    {
    }

    #[TestWith([ExistingProviderConstants::CYCLE_A])]
    public function testCyclicClassConstantTestWithIsConservative(string $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::ANSWER])]
    public function testInvalidIntClassConstantForStringTestWith(string $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::TEXT])]
    public function testInvalidStringClassConstantForIntTestWith(int $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::ALIAS])]
    public function testInvalidAliasClassConstantForStringTestWith(string $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::PARENT_TEXT])]
    public function testInvalidParentClassConstantForIntTestWith(int $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::IFACE_TEXT])]
    public function testInvalidInterfaceClassConstantForIntTestWith(int $value): void
    {
    }

    #[TestWith([ExistingProviderConstants::TRAIT_CODE])]
    public function testInvalidTraitClassConstantForStringTestWith(string $value): void
    {
    }

    #[TestWith([TestWithStringMode::DEFAULT_CODE])]
    public function testInvalidEnumClassConstantForStringTestWith(string $code): void
    {
    }

    #[TestWith([ExistingProviderConstants::DOES_NOT_EXIST])]
    public function testUnknownClassConstantControlTestWith(string $value): void
    {
    }

    #[TestWith(ExistingProviderConstants::VALID_ROW)]
    public function testValidWholeRowClassConstantTestWith(string $value): void
    {
    }

    #[TestWith(ExistingProviderConstants::INVALID_ROW)]
    public function testInvalidWholeRowClassConstantTestWith(string $value): void
    {
    }

    #[TestWith(ExistingProviderConstants::MISSING_ROW)]
    public function testMissingWholeRowClassConstantControlTestWith(string $value): void
    {
    }
}
