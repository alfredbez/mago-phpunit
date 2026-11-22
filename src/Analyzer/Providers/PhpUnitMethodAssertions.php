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
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
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
            'assertjson' => $this->jsonAssertion(),
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
        $actual = $context->invocation->getArgument(1, 'object')?->type;
        if ($property === null || $actual === null) {
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
        if ($expected === null || !self::isSingleton($expected)) {
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
        $haystack = $context->invocation->getArgument(1, 'haystack')?->type;
        if ($haystack === null) {
            return null;
        }

        $values = [];
        foreach ($haystack->atomicTypes as $atomic) {
            if ($atomic instanceof ListType) {
                $values[] = $atomic->elementType;
            } elseif ($atomic instanceof KeyedArrayType) {
                if ($atomic->valueType !== null) {
                    $values[] = $atomic->valueType;
                }
                foreach ($atomic->knownItems ?? [] as $item) {
                    $values[] = $item->type;
                }
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
        $haystack = $context->invocation->getArgument(1, 'haystack')?->type;
        if ($haystack === null) {
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
        $actual = $context->invocation->getArgument(0, 'actual')?->type;
        if ($actual === null) {
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

        $actual = $context->invocation->getArgument(0, 'array')?->type;
        if ($actual === null) {
            return null;
        }
        $values = [];
        foreach ($actual->atomicTypes as $atomic) {
            if ($atomic instanceof ListType) {
                $values[] = $atomic->elementType;
            } elseif ($atomic instanceof KeyedArrayType) {
                if ($atomic->valueType !== null) {
                    $values[] = $atomic->valueType;
                }
                foreach ($atomic->knownItems ?? [] as $item) {
                    $values[] = $item->type;
                }
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

    private function notEmptyAssertion(AssertionProviderContext $context): ?InvocationAssertions
    {
        $method = $context->codebase->getMethod(self::ASSERT_CLASS, 'assertNotEmpty');
        if (($method?->assertions['$actual'] ?? []) !== []) {
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
        if (!$needleType instanceof Type || !self::isNonEmptyString($needleType)) {
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
        if (!$affixType instanceof Type || !self::isNonEmptyString($affixType)) {
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

    private function jsonAssertion(): InvocationAssertions
    {
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

        $actualType = $context->invocation->getArgument(1, 'actual')?->type;
        if (!$actualType instanceof Type || !self::isPureInt($actualType)) {
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
        if ($type->atomicTypes === []) {
            return false;
        }

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
        if ($type->atomicTypes === []) {
            return false;
        }

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
