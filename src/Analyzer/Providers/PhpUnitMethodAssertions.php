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
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\ResourceType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
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
            'assertcount' => $this->countAssertion($context),
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

    private function countAssertion(AssertionProviderContext $context): ?InvocationAssertions
    {
        $expectedCount = $context->invocation->getArgument(0, 'expectedCount')?->type?->getLiteralInt();
        if ($expectedCount === null || $expectedCount < 0) {
            return null;
        }

        $assertion = $expectedCount === 0
            ? new SimpleAssertion(SimpleAssertionKind::EmptyCountable)
            : new CountabilityAssertion(CountabilityAssertionKind::NonEmpty, false);

        return new InvocationAssertions(
            assertions: [
                '$haystack' => [$assertion],
            ],
        );
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
