<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\Assertion\ArrayKeyAssertion;
use Mago\Sdk\Analyzer\Assertion\ArrayKeyAssertionKind;
use Mago\Sdk\Analyzer\Assertion\CountabilityAssertion;
use Mago\Sdk\Analyzer\Assertion\CountabilityAssertionKind;
use Mago\Sdk\Analyzer\Assertion\IntegerAssertion;
use Mago\Sdk\Analyzer\Assertion\IntegerAssertionKind;
use Mago\Sdk\Analyzer\Assertion\SimpleAssertion;
use Mago\Sdk\Analyzer\Assertion\SimpleAssertionKind;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\MethodAssertionProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\EnumType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectProperty;
use Mago\Sdk\Analyzer\Type\ObjectShapeType;
use Mago\Sdk\Analyzer\Type\ResourceType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\StringType;

/**
 * Narrows PHPUnit Assert methods that lack native @phpstan-assert/@psalm-assert annotations.
 *
 * @internal
 */
final class PhpUnitMethodAssertions implements MethodAssertionProvider
{
    private const ASSERT_CLASS = 'PHPUnit\\Framework\\Assert';

    public const METHODS = [
        'assertArrayNotHasKey',
        'assertCount',
        'assertNotCount',
        'assertObjectHasProperty',
        'assertNotSame',
        'assertContains',
        'assertNotContains',
        'assertEmpty',
        'assertIsList',
        'assertNotEmpty',
        'assertContainsOnly',
        'assertStringContainsString',
        'assertStringContainsStringIgnoringCase',
        'assertStringStartsWith',
        'assertStringEndsWith',
        'assertJson',
        'assertGreaterThan',
        'assertGreaterThanOrEqual',
        'assertLessThan',
        'assertLessThanOrEqual',
    ];

    public function getTargets(): array
    {
        $targets = [];
        foreach (self::METHODS as $method) {
            $targets[] = MethodTarget::exact(self::ASSERT_CLASS, $method);
        }

        return $targets;
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $name = $context->invocation->name;
        $short = str_contains($name, '\\') ? substr($name, strrpos($name, '\\') + 1) : $name;

        return match (strtolower($short)) {
            'assertarraynothaskey' => $this->arrayNotHasKey($context),
            'assertcount' => $this->countAssertion($context, IntegerAssertionKind::HasExactCount),
            'assertnotcount' => $this->countAssertion($context, IntegerAssertionKind::DoesNotHaveExactCount),
            'assertobjecthasproperty' => $this->objectHasProperty($context),
            'assertnotsame' => $this->notSame($context),
            'assertcontains' => $this->contains($context),
            'assertnotcontains' => $this->notContains($context),
            'assertempty' => $this->emptyAssertion($context),
            'assertislist' => $this->isList($context),
            'assertnotempty' => $this->notEmptyAssertion($context),
            'assertcontainsonly' => $this->containsOnly($context),
            'assertstringcontainsstring', 'assertstringcontainsstringignoringcase' => $this->stringContainsString($context),
            'assertstringstartswith', 'assertstringendswith' => $this->stringAffixAssertion($context),
            'assertjson' => $this->jsonAssertion($context),
            'assertgreaterthan' => $this->integerComparisonAssertion($context, IntegerAssertionKind::IsGreaterThan, 'minimum'),
            'assertgreaterthanorequal' => $this->integerComparisonAssertion($context, IntegerAssertionKind::IsGreaterThanOrEqual, 'minimum'),
            'assertlessthan' => $this->integerComparisonAssertion($context, IntegerAssertionKind::IsLessThan, 'maximum'),
            'assertlessthanorequal' => $this->integerComparisonAssertion($context, IntegerAssertionKind::IsLessThanOrEqual, 'maximum'),
            default => null,
        };
    }

    private function arrayNotHasKey(AssertionProviderContext $context): ?InvocationAssertions
    {
        $type = $context->invocation->getArgument(0, 'key')?->type;
        if (!$type instanceof Type) {
            return null;
        }

        $key = self::literalKey($type);
        if ($key === null) {
            return null;
        }

        $arrayArg = $context->invocation->getArgument(1, 'array');
        $arrayType = $arrayArg?->type;
        if (
            $arrayArg === null
            || !$arrayType instanceof Type
            || !self::isArrayOrMixedType($arrayType)
            || !self::isNarrowableExpression($arrayArg->expression)
        ) {
            return null;
        }

        return new InvocationAssertions(
            assertions: [
                '$array' => [
                    new ArrayKeyAssertion(ArrayKeyAssertionKind::DoesNotHaveKey, $key),
                ],
            ],
        );
    }

    private function countAssertion(AssertionProviderContext $context, IntegerAssertionKind $kind): ?InvocationAssertions
    {
        $expectedCount = $context->invocation->getArgument(0, 'expectedCount')?->type?->getLiteralInt();
        if ($expectedCount === null || $expectedCount < 0) {
            return null;
        }

        $haystackArg = $context->invocation->getArgument(1, 'haystack');
        if ($haystackArg === null || !self::isNarrowableExpression($haystackArg->expression)) {
            return null;
        }

        $assertions = $kind === IntegerAssertionKind::HasExactCount && $expectedCount === 0
            ? [new SimpleAssertion(SimpleAssertionKind::EmptyCountable)]
            : [new IntegerAssertion($kind, $expectedCount)];
        if ($kind === IntegerAssertionKind::DoesNotHaveExactCount && $expectedCount === 0) {
            $assertions[] = new CountabilityAssertion(CountabilityAssertionKind::NonEmpty, false);
        }

        return new InvocationAssertions(
            assertions: [
                '$haystack' => $assertions,
            ],
        );
    }

    private function objectHasProperty(AssertionProviderContext $context): ?InvocationAssertions
    {
        $property = $context->invocation->getArgument(0, 'propertyName')?->type?->getLiteralString();
        $actualArg = $context->invocation->getArgument(1, 'object');
        $actual = $actualArg?->type;
        if ($property === null || $actualArg === null || $actual === null || !self::isNarrowableExpression($actualArg->expression)) {
            return null;
        }

        $properties = [];
        foreach ($actual->atomicTypes as $atomic) {
            if ($atomic instanceof ObjectShapeType && count($actual->atomicTypes) === 1) {
                foreach ($atomic->properties as $existing) {
                    $properties[$existing->name] = $existing;
                }
            } elseif (!$atomic instanceof AnyObjectType && !$atomic instanceof MixedType) {
                return null;
            }
        }
        $properties[$property] = new ObjectProperty($property, false, $properties[$property]->type ?? Type::mixed());

        return new InvocationAssertions(assertions: [
            '$object' => [new TypeAssertion(
                TypeAssertionKind::IsType,
                Type::fromAtomic(new ObjectShapeType(array_values($properties), sealed: false)),
            )],
        ]);
    }

    private function notSame(AssertionProviderContext $context): ?InvocationAssertions
    {
        $expected = $context->invocation->getArgument(0, 'expected')?->type;
        $actualArg = $context->invocation->getArgument(1, 'actual');
        if ($expected === null || $actualArg === null || !self::isSingleton($expected) || !self::isNarrowableExpression($actualArg->expression)) {
            return null;
        }

        $assertions = [new TypeAssertion(TypeAssertionKind::IsNotIdentical, $expected)];
        if ($expected->atomicTypes[0] instanceof EnumType) {
            $assertions[] = new TypeAssertion(TypeAssertionKind::IsNotType, $expected);
        }

        return new InvocationAssertions(assertions: ['$actual' => $assertions]);
    }

    private function contains(AssertionProviderContext $context): ?InvocationAssertions
    {
        $needleArg = $context->invocation->getArgument(0, 'needle');
        $haystack = $context->invocation->getArgument(1, 'haystack')?->type;
        if ($needleArg === null || $haystack === null || !self::isNarrowableExpression($needleArg->expression)) {
            return null;
        }

        $values = [];
        foreach ($haystack->atomicTypes as $atomic) {
            if ($atomic instanceof ListType) {
                self::collectListValues($atomic, $values);
            } elseif ($atomic instanceof KeyedArrayType) {
                self::collectKeyedArrayValues($atomic, $values);
            } else {
                return null;
            }
        }
        if ($values === []) {
            return null;
        }

        return new InvocationAssertions(assertions: [
            '$needle' => [new TypeAssertion(TypeAssertionKind::InArray, self::union($values))],
        ]);
    }

    private function notContains(AssertionProviderContext $context): ?InvocationAssertions
    {
        $needleArg = $context->invocation->getArgument(0, 'needle');
        $haystack = $context->invocation->getArgument(1, 'haystack')?->type;
        if ($needleArg === null || $haystack === null || !self::isNarrowableExpression($needleArg->expression)) {
            return null;
        }

        $guaranteed = null;
        foreach ($haystack->atomicTypes as $atomic) {
            $items = match (true) {
                $atomic instanceof ListType => $atomic->knownElements,
                $atomic instanceof KeyedArrayType => $atomic->knownItems,
                default => null,
            };
            if ($items === null) {
                return null;
            }
            $literals = [];
            foreach ($items as $item) {
                if (!$item->optional && self::isSingleton($item->type)) {
                    $literals[self::singletonKey($item->type)] = $item->type;
                }
            }
            $guaranteed = $guaranteed === null ? $literals : array_intersect_key($guaranteed, $literals);
        }
        if ($guaranteed === null || $guaranteed === []) {
            return null;
        }

        $needle = $context->invocation->getArgument(0, 'needle')?->type;
        if ($needle !== null) {
            $remaining = [];
            foreach ($needle->atomicTypes as $atomic) {
                $member = Type::fromAtomic($atomic);
                if (!self::isSingleton($member) || !isset($guaranteed[self::singletonKey($member)])) {
                    $remaining[] = $member;
                }
            }
            if ($remaining !== [] && count($remaining) < count($needle->atomicTypes)) {
                return new InvocationAssertions(assertions: [
                    '$needle' => [new TypeAssertion(TypeAssertionKind::InArray, self::union($remaining))],
                ]);
            }
        }

        return new InvocationAssertions(assertions: [
            '$needle' => [new TypeAssertion(TypeAssertionKind::NotInArray, self::union(array_values($guaranteed)))],
        ]);
    }

    private function emptyAssertion(AssertionProviderContext $context): ?InvocationAssertions
    {
        $method = $context->codebase->getMethod(self::ASSERT_CLASS, 'assertEmpty');
        if (($method?->assertions['$actual'] ?? []) !== []) {
            return null;
        }
        $actualArg = $context->invocation->getArgument(0, 'actual');
        $actual = $actualArg?->type;
        if ($actualArg === null || $actual === null || !self::isNarrowableExpression($actualArg->expression)) {
            return null;
        }
        foreach ($actual->atomicTypes as $atomic) {
            if (
                !$atomic instanceof ScalarType && !$atomic instanceof ListType
                && !$atomic instanceof KeyedArrayType && !$atomic instanceof ResourceType
                && !($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null)
            ) {
                return null;
            }
        }

        $kind = SimpleAssertionKind::Empty;
        foreach ($actual->atomicTypes as $atomic) {
            if ($atomic instanceof ScalarType) {
                $kind = SimpleAssertionKind::Falsy;
                break;
            }
        }

        return new InvocationAssertions(assertions: [
            '$actual' => [new SimpleAssertion($kind)],
        ]);
    }

    private function isList(AssertionProviderContext $context): ?InvocationAssertions
    {
        $method = $context->codebase->getMethod(self::ASSERT_CLASS, 'assertIsList');
        if (($method?->assertions['$array'] ?? []) !== []) {
            return null;
        }

        $actualArg = $context->invocation->getArgument(0, 'array');
        $actual = $actualArg?->type;
        if ($actualArg === null || $actual === null || !self::isNarrowableExpression($actualArg->expression)) {
            return null;
        }
        $values = [];
        foreach ($actual->atomicTypes as $atomic) {
            if ($atomic instanceof ListType) {
                self::collectListValues($atomic, $values);
            } elseif ($atomic instanceof KeyedArrayType) {
                self::collectKeyedArrayValues($atomic, $values);
            } elseif ($atomic instanceof MixedType) {
                $values[] = Type::mixed();
            }
        }

        return new InvocationAssertions(assertions: [
            '$array' => [new TypeAssertion(TypeAssertionKind::IsType, Type::list($values === [] ? Type::mixed() : self::union($values)))],
        ]);
    }

    private static function isSingleton(Type $type): bool
    {
        if (count($type->atomicTypes) !== 1) {
            return false;
        }
        $atomic = $type->atomicTypes[0];

        return ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null)
            || ($atomic instanceof EnumType && $atomic->case !== null)
            || $type->getLiteralInt() !== null
            || $type->getLiteralString() !== null
            || $type->getLiteralBool() !== null;
    }

    private static function singletonKey(Type $type): string
    {
        $atomic = $type->atomicTypes[0];
        if ($atomic instanceof EnumType) {
            return 'enum:' . strtolower($atomic->name) . '::' . $atomic->case;
        }
        if ($type->getLiteralInt() !== null) {
            return 'int:' . $type->getLiteralInt();
        }
        if ($type->getLiteralString() !== null) {
            return 'string:' . $type->getLiteralString();
        }
        if ($type->getLiteralBool() !== null) {
            return $type->getLiteralBool() ? 'true' : 'false';
        }

        return 'null';
    }

    /** @param non-empty-list<Type> $types */
    private static function union(array $types): Type
    {
        return count($types) === 1 ? $types[0] : Type::union(...$types);
    }

    /** @param list<Type> $values */
    private static function collectListValues(ListType $atomic, array &$values): void
    {
        foreach ($atomic->knownElements ?? [] as $element) {
            $values[] = $element->type;
        }
        if (!self::isNever($atomic->elementType)) {
            $values[] = $atomic->elementType;
        }
    }

    /** @param list<Type> $values */
    private static function collectKeyedArrayValues(KeyedArrayType $atomic, array &$values): void
    {
        if ($atomic->valueType !== null && !self::isNever($atomic->valueType)) {
            $values[] = $atomic->valueType;
        }
        foreach ($atomic->knownItems ?? [] as $item) {
            $values[] = $item->type;
        }
    }

    private static function isNever(Type $type): bool
    {
        return count($type->atomicTypes) === 1
            && $type->atomicTypes[0] instanceof SimpleAtomicType
            && $type->atomicTypes[0]->kind === SimpleAtomicTypeKind::Never;
    }

    /**
     * Returns true when the type has at least one array, `mixed`, or array-constrained generic branch
     * and contains no `iterable` or named-object (`ArrayAccess`) branch that Mago reconciles to `never`.
     */
    public static function isArrayOrMixedType(Type $type, int $depth = 0): bool
    {
        if ($depth > 4) {
            return false;
        }

        $hasArrayOrMixed = false;
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof IterableType || $atomic instanceof NamedObjectType) {
                return false;
            }
            if ($atomic instanceof ListType || $atomic instanceof KeyedArrayType || $atomic instanceof MixedType) {
                $hasArrayOrMixed = true;
                continue;
            }
            if ($atomic instanceof GenericParameterType) {
                if (!self::isArrayOrMixedType($atomic->constraint, $depth + 1)) {
                    return false;
                }
                $hasArrayOrMixed = true;
            }
        }

        return $hasArrayOrMixed;
    }

    /**
     * Returns true when the expression refers to a variable, property, or static property that can be narrowed,
     * excluding constants, literals, and class constant fetches (including reserved-keyword constant names).
     */
    public static function isNarrowableExpression(string $expression): bool
    {
        $trimmed = trim($expression);
        if ($trimmed === '' || !str_contains($trimmed, '$')) {
            return false;
        }

        $tokens = [];
        foreach (token_get_all('<?php ' . $trimmed) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $token;
        }

        while (count($tokens) >= 2 && $tokens[0] === '(' && $tokens[count($tokens) - 1] === ')') {
            $depth = 0;
            $wrapsAll = true;
            $last = count($tokens) - 1;
            for ($i = 0; $i < $last; ++$i) {
                if ($tokens[$i] === '(') {
                    ++$depth;
                } elseif ($tokens[$i] === ')') {
                    --$depth;
                    if ($depth === 0) {
                        $wrapsAll = false;
                        break;
                    }
                }
            }
            if (!$wrapsAll) {
                break;
            }
            $tokens = array_slice($tokens, 1, -1);
        }

        $depth = 0;
        $hasTopLevelVariable = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '(' || $token === '[' || $token === '{') {
                ++$depth;
                continue;
            }
            if ($token === ')' || $token === ']' || $token === '}') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth !== 0 || !is_array($token)) {
                continue;
            }

            if ($token[0] === T_VARIABLE) {
                $hasTopLevelVariable = true;
            } elseif ($token[0] === T_DOUBLE_COLON) {
                $next = $tokens[$i + 1] ?? null;
                $after = $tokens[$i + 2] ?? null;
                if ($next === '{' || (is_array($next) && $next[0] !== T_VARIABLE && $after !== '(')) {
                    return false;
                }
            }
        }

        return $hasTopLevelVariable;
    }

    private function notEmptyAssertion(AssertionProviderContext $context): ?InvocationAssertions
    {
        $method = $context->codebase->getMethod(self::ASSERT_CLASS, 'assertNotEmpty');
        if (($method?->assertions['$actual'] ?? []) !== []) {
            return null;
        }

        $actualArg = $context->invocation->getArgument(0, 'actual');
        if ($actualArg === null || !self::isNarrowableExpression($actualArg->expression)) {
            return null;
        }

        return new InvocationAssertions(
            assertions: [
                '$actual' => [
                    new SimpleAssertion(SimpleAssertionKind::NonEmpty),
                ],
            ],
        );
    }

    private function containsOnly(AssertionProviderContext $context): ?InvocationAssertions
    {
        $method = $context->codebase->getMethod(self::ASSERT_CLASS, 'assertContainsOnly');
        if (($method?->assertions['$haystack'] ?? []) !== []) {
            return null;
        }

        $haystackArg = $context->invocation->getArgument(1, 'haystack');
        if ($haystackArg === null || !self::isNarrowableExpression($haystackArg->expression)) {
            return null;
        }

        $typeName = $context->invocation->getArgument(0, 'type')?->type?->getLiteralString();
        if ($typeName === null || $typeName === '') {
            return null;
        }

        $isNativeType = $context->invocation->getArgument(2, 'isNativeType')?->type?->getLiteralBool();
        $elementType = null;
        if ($isNativeType !== false) {
            $elementType = self::nativeType(strtolower($typeName));
        }

        if ($elementType === null && $isNativeType !== true && $context->codebase->classLikeExists($typeName)) {
            $elementType = Type::namedObject($typeName);
        }

        if ($elementType === null) {
            return null;
        }

        return new InvocationAssertions(
            assertions: [
                '$haystack' => [
                    new TypeAssertion(
                        TypeAssertionKind::IsType,
                        Type::fromAtomic(new IterableType(Type::mixed(), $elementType, null)),
                    ),
                ],
            ],
        );
    }

    private function stringContainsString(AssertionProviderContext $context): ?InvocationAssertions
    {
        $needleType = $context->invocation->getArgument(0, 'needle')?->type;
        $haystackArg = $context->invocation->getArgument(1, 'haystack');
        if (
            !$needleType instanceof Type
            || !self::isNonEmptyString($needleType)
            || $haystackArg === null
            || !self::isNarrowableExpression($haystackArg->expression)
        ) {
            return null;
        }

        return new InvocationAssertions(
            assertions: [
                '$haystack' => [
                    new TypeAssertion(TypeAssertionKind::IsType, Type::nonEmptyString()),
                ],
            ],
        );
    }

    private function stringAffixAssertion(AssertionProviderContext $context): ?InvocationAssertions
    {
        $affixType = $context->invocation->getArgument(0, 'prefix', 'suffix')?->type;
        $stringArg = $context->invocation->getArgument(1, 'string');
        if (
            !$affixType instanceof Type
            || !self::isNonEmptyString($affixType)
            || $stringArg === null
            || !self::isNarrowableExpression($stringArg->expression)
        ) {
            return null;
        }

        return new InvocationAssertions(
            assertions: [
                '$string' => [
                    new TypeAssertion(TypeAssertionKind::IsType, Type::nonEmptyString()),
                ],
            ],
        );
    }

    private function jsonAssertion(AssertionProviderContext $context): ?InvocationAssertions
    {
        $actualArg = $context->invocation->getArgument(0, 'actual');
        if ($actualArg === null || !self::isNarrowableExpression($actualArg->expression)) {
            return null;
        }

        return new InvocationAssertions(
            assertions: [
                '$actual' => [
                    new TypeAssertion(TypeAssertionKind::IsType, Type::nonEmptyString()),
                ],
            ],
        );
    }

    private function integerComparisonAssertion(
        AssertionProviderContext $context,
        IntegerAssertionKind $kind,
        string $boundName,
    ): ?InvocationAssertions {
        $bound = $context->invocation->getArgument(0, $boundName)?->type?->getLiteralInt();
        if ($bound === null) {
            return null;
        }

        $actualArg = $context->invocation->getArgument(1, 'actual');
        $actualType = $actualArg?->type;
        if (
            $actualArg === null
            || !$actualType instanceof Type
            || !self::isPureInt($actualType)
            || !self::isNarrowableExpression($actualArg->expression)
        ) {
            return null;
        }

        return new InvocationAssertions(
            assertions: [
                '$actual' => [
                    new IntegerAssertion($kind, $bound),
                ],
            ],
        );
    }

    private static function isPureInt(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (!$atomic instanceof ScalarType || $atomic->kind !== ScalarTypeKind::Integer) {
                return false;
            }
        }

        return true;
    }

    private static function literalKey(Type $type): ?ArrayKey
    {
        $string = $type->getLiteralString();
        if ($string !== null) {
            return new ArrayKey(ArrayKeyKind::String, $string);
        }

        $integer = $type->getLiteralInt();
        if ($integer !== null) {
            return new ArrayKey(ArrayKeyKind::Integer, $integer);
        }

        return null;
    }

    private static function isNonEmptyString(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (!$atomic instanceof ScalarType) {
                return false;
            }
            if ($atomic->refinement instanceof ClassLikeStringType) {
                continue;
            }
            if (!$atomic->refinement instanceof StringType || !$atomic->refinement->nonEmpty) {
                return false;
            }
        }

        return true;
    }

    private static function nativeType(string $name): ?Type
    {
        return match ($name) {
            'array' => Type::array(Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed()),
            'bool', 'boolean' => Type::bool(),
            'callable' => Type::fromAtomic(new CallableType(new CallableSignature(false, false, [], null, null, []), null)),
            'double', 'float', 'real' => Type::float(),
            'int', 'integer' => Type::int(),
            'iterable' => Type::fromAtomic(new IterableType(Type::mixed(), Type::mixed(), null)),
            'null' => Type::null(),
            'numeric' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Numeric)),
            'object' => Type::object(),
            'resource' => Type::fromAtomic(new ResourceType(false)),
            'resource (closed)' => Type::fromAtomic(new ResourceType(true)),
            'scalar' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Scalar)),
            'string' => Type::string(),
            default => null,
        };
    }
}
