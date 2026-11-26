<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\NodeKind;

final class LegacyMockCalls implements NodeAnalysisHook
{
    private const MOCK = 'phpunit\\framework\\mockobject\\mockobject';
    private const INVOCATION_ORDER = 'PHPUnit\\Framework\\MockObject\\Rule\\InvocationOrder';
    private const INVOCATION_RULE = 'PHPUnit\\Framework\\MockObject\\Rule\\InvocationRule';
    private const CONSTRAINT = 'PHPUnit\\Framework\\Constraint\\Constraint';

    public function getTargets(): array
    {
        return [NodeKind::MethodCall, NodeKind::NullSafeMethodCall];
    }

    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::SourceText,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::ExpressionTypes,
        ];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $call = CallExpression::fromNode($context->source, $context->node);
        $called = strtolower($call->getName($context->source) ?? '');
        if ($called !== 'method' && $called !== 'expects') {
            return;
        }
        if (count($call->arguments) !== 1 || $call->arguments[0]->unpacked) {
            return;
        }
        $argType = $context->analysis->getExpressionType($call->arguments[0]->value);
        if (!self::isCompatiblePlainCallArgument($context, $called, $argType)) {
            return;
        }
        $type = $call->receiver === null ? null : $context->analysis->getExpressionType($call->receiver);
        if (self::isLegacyUnion($type)) {
            return;
        }
        if ($type === null || count($type->atomicTypes) !== 1 || !$type->atomicTypes[0] instanceof NamedObjectType) {
            return;
        }
        $atomic = $type->atomicTypes[0];
        if ($atomic->intersections !== null && $atomic->intersections !== []) {
            return;
        }
        $name = $call->getName($context->source);
        if ($name === null || !self::isPlainMockTarget($context->codebase, $atomic->name, $name)) {
            return;
        }
        $inTest = false;
        foreach ($context->source->getAncestors($context->node) as $ancestor) {
            if ($ancestor->kind !== NodeKind::Class_) {
                continue;
            }
            foreach ($context->source->getChildren($ancestor) as $child) {
                if ($child->kind !== NodeKind::LocalIdentifier) {
                    continue;
                }
                $owner = $context->source->getResolvedName($child)?->name;
                $inTest = self::isTestClass($context->codebase, $owner);
            }
            break;
        }
        if (!$inTest) {
            return;
        }
        $context->report(Level::Error, 'mock-call-on-plain-type', Issue::at(
            "PHPUnit {$name}() called on plain type `{$atomic->name}`.",
            new SourceLocation($context->analysis->file, $context->node->span),
        )->withHelp("Declare the double as {$atomic->name}&MockObject so its mocked API is known."));
    }

    public static function isTestClass(Codebase $codebase, ?string $name): bool
    {
        foreach ($name === null ? [] : ($codebase->getClassLike($name)?->parentClasses ?? []) as $parent) {
            if (strcasecmp($parent, 'PHPUnit\\Framework\\TestCase') === 0) {
                return true;
            }
        }
        return false;
    }

    public static function isPlainMockTarget(Codebase $codebase, string $name, string $method): bool
    {
        $class = $codebase->getClassLike($name);
        if ($class === null || $class->hasIncompleteHierarchy() || $class->mixins !== []
            || $codebase->methodExists($name, $method) || $codebase->methodExists($name, '__call')
            || str_starts_with(strtolower($name), 'phpunit\\')) {
            return false;
        }
        foreach ($class->pseudoMethods as $pseudo) {
            if (strcasecmp($pseudo, $method) === 0) {
                return false;
            }
        }
        return true;
    }

    public static function isLegacyUnion(?Type $type): bool
    {
        if ($type === null || count($type->atomicTypes) < 2) {
            return false;
        }
        $hasMock = false;
        $hasTarget = false;
        foreach ($type->atomicTypes as $atomic) {
            if (!$atomic instanceof NamedObjectType || ($atomic->intersections !== null && $atomic->intersections !== [])) {
                return false;
            }
            if (strtolower($atomic->name) === self::MOCK) {
                $hasMock = true;
            } else {
                $hasTarget = true;
            }
        }

        return $hasMock && $hasTarget;
    }

    public static function isCompatiblePlainCallArgument(NodeAnalysisContext $context, string $method, ?Type $type): bool
    {
        if ($type === null) {
            return true;
        }
        if ($method === 'expects') {
            $expected = Type::union(
                Type::namedObject(self::INVOCATION_ORDER),
                Type::namedObject(self::INVOCATION_RULE),
            );
            foreach ($type->atomicTypes as $atomic) {
                if ($atomic instanceof MixedType || $atomic instanceof AnyObjectType) {
                    return true;
                }
                if ($atomic instanceof GenericParameterType && self::isCompatiblePlainCallArgument($context, $method, $atomic->constraint)) {
                    return true;
                }
                if ($atomic instanceof NamedObjectType && $context->types->isContainedBy(Type::fromAtomic($atomic), $expected)) {
                    return true;
                }
            }
            return false;
        }

        $expected = Type::union(Type::string(), Type::namedObject(self::CONSTRAINT));
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType) {
                return true;
            }
            if ($atomic instanceof GenericParameterType && self::isCompatiblePlainCallArgument($context, $method, $atomic->constraint)) {
                return true;
            }
            if ($context->types->isContainedBy(Type::fromAtomic($atomic), $expected)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Metadata-only fallback for IssueFilterHook, which has Codebase access without a TypeComparison service.
     */
    public static function isTypeCompatibleWithPlainCall(Codebase $codebase, string $method, ?Type $type): bool
    {
        if ($type === null) {
            return true;
        }
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType) {
                return true;
            }
            if ($atomic instanceof GenericParameterType && self::isTypeCompatibleWithPlainCall($codebase, $method, $atomic->constraint)) {
                return true;
            }
            if ($method === 'expects') {
                if ($atomic instanceof AnyObjectType) {
                    return true;
                }
                if ($atomic instanceof NamedObjectType && self::isClassCompatibleWith($codebase, $atomic->name, [self::INVOCATION_ORDER, self::INVOCATION_RULE])) {
                    return true;
                }
                continue;
            }
            if ($atomic instanceof ScalarType
                && ($atomic->kind === ScalarTypeKind::String || $atomic->kind === ScalarTypeKind::ClassLikeString)) {
                return true;
            }
            if ($atomic instanceof NamedObjectType && self::isClassCompatibleWith($codebase, $atomic->name, [self::CONSTRAINT])) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $targets */
    public static function isClassCompatibleWith(Codebase $codebase, string $className, array $targets): bool
    {
        foreach ($targets as $target) {
            if (strcasecmp($className, $target) === 0) {
                return true;
            }
        }
        $meta = $codebase->getClassLike($className);
        if ($meta === null) {
            return true;
        }
        foreach ($meta->parentClasses as $parent) {
            foreach ($targets as $target) {
                if (strcasecmp($parent, $target) === 0) {
                    return true;
                }
            }
        }
        foreach ($meta->parentInterfaces as $interface) {
            foreach ($targets as $target) {
                if (strcasecmp($interface, $target) === 0) {
                    return true;
                }
            }
        }
        return false;
    }
}
