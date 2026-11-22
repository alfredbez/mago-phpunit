<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\CallExpression;

final class AssertionQualityHook implements MethodCallAnalysisHook, NodeAnalysisHook
{
    private const METHODS = [
        'assertNotNull', 'assertNull', 'assertTrue', 'assertFalse', 'assertInstanceOf',
        'assertIsArray', 'assertIsBool', 'assertIsCallable', 'assertIsFloat', 'assertIsInt',
        'assertIsIterable', 'assertIsNumeric', 'assertIsObject', 'assertIsResource',
        'assertIsScalar', 'assertIsString', 'assertIsList', 'assertIsClosedResource', 'assertSame',
        'assertIsNotArray', 'assertIsNotBool', 'assertIsNotCallable', 'assertIsNotFloat', 'assertIsNotInt',
        'assertIsNotIterable', 'assertIsNotNumeric', 'assertIsNotObject', 'assertIsNotResource',
        'assertIsNotClosedResource', 'assertIsNotScalar', 'assertIsNotString',
    ];

    public function __construct(private readonly bool $functions = false) {}

    public function getTargets(): array
    {
        return $this->functions ? [NodeKind::FunctionCall] : array_map(
            fn(string $method) => MethodTarget::exact('PHPUnit\\Framework\\Assert', $method), self::METHODS,
        );
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText, FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::ArgumentTypes, FileAnalysisRequirement::ExpressionTypes];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $call = CallExpression::fromNode($context->source, $context->node);
        $name = strtolower($call->getName($context->source) ?? '');
        if ($this->functions) {
            $resolvedNames = $context->source->getResolvedNames($call->callee);
            $resolved = $resolvedNames[0]->name ?? $name;
            if (!str_starts_with(strtolower(ltrim($resolved, '\\')), 'phpunit\\framework\\')) {
                return;
            }
            $name = strtolower($resolved);
        }
        $name = substr($name, (int) strrpos('\\' . $name, '\\'));
        $arguments = [];
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked) {
                return;
            }
            $arguments[$argument->name ?? $argument->index] = [
                $context->argumentTypes[$argument->index] ?? null,
                $argument->value,
            ];
        }
        $actualPosition = in_array($name, ['assertsame', 'assertinstanceof'], true) ? 1 : 0;
        [$actual, $actualNode] = $arguments['actual'] ?? $arguments['object'] ?? $arguments['array'] ?? $arguments[$actualPosition] ?? [null, null];
        if ($name === 'assertsame' && $actualNode !== null) {
            $count = CallExpression::fromExpression($context->source, $actualNode);
            $countName = $count?->getName($context->source);
            if ($count !== null && strtolower(ltrim($countName ?? '', '\\')) === 'count') {
                $isCount = false;
                if ($count->isMethod() && $count->receiver !== null && $count->arguments === []) {
                    $receiver = $context->analysis->getExpressionType($count->receiver);
                    $isCount = $receiver !== null && $context->types->isContainedBy($receiver, Type::namedObject('Countable'));
                }
                if ($count->isFunction() && count($count->arguments) === 1) {
                    $names = $context->source->getResolvedNames($count->callee);
                    $isCount = $names === [];
                    $resolvedName = $names[0]->name ?? '';
                    if ($resolvedName !== '' && !$context->codebase->functionExists($resolvedName)) {
                        $isCount = true;
                    }
                    foreach ($names as $resolved) {
                        $isCount = $isCount || strtolower(ltrim($resolved->name, '\\')) === 'count';
                    }
                }
                if ($isCount) {
                    $this->report($context, 'assert-same-count', 'Use assertCount() to compare a collection count.');
                }
            }
        }
        if (!$actual instanceof Type || !$this->isKnown($actual)) {
            return;
        }
        $expected = null;
        if ($name === 'assertnotnull') {
            if (!$context->types->canBeIdentical($actual, Type::null())) {
                $this->redundant($context, $name);
            }
            return;
        }
        if ($name === 'assertsame') {
            [$first] = $arguments['expected'] ?? $arguments[0] ?? [null];
            if ($first instanceof Type && $this->singleton($first) && $this->singleton($actual) && $context->types->equals($first, $actual)) {
                $this->redundant($context, $name);
            }
            return;
        }
        if ($name === 'assertinstanceof') {
            [$first] = $arguments['expected'] ?? $arguments[0] ?? [null];
            if ($first instanceof Type && count($first->atomicTypes) === 1) {
                foreach ($first->atomicTypes as $atomic) {
                    if (!$atomic instanceof ScalarType || $atomic->kind !== ScalarTypeKind::ClassLikeString || $atomic->refinement?->variant !== ClassLikeStringVariant::Literal) {
                        return;
                    }
                    $class = $atomic->refinement->literal;
                    if ($class === null || !$context->codebase->classOrInterfaceExists($class)) {
                        return;
                    }
                    $expected = Type::namedObject($class);
                }
            }
        } else {
            $typeAssertion = str_replace('assertisnot', 'assertis', $name);
            $expected = match ($typeAssertion) {
                'assertnull' => Type::null(),
                'asserttrue' => Type::true(),
                'assertfalse' => Type::false(),
                'assertisarray' => Type::array(Type::mixed(), Type::mixed()),
                'assertisbool' => Type::bool(),
                'assertisfloat' => Type::float(),
                'assertisint' => Type::int(),
                'assertisobject' => Type::object(),
                'assertisstring' => Type::string(),
                'assertisscalar' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Scalar)),
                'assertisnumeric' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Numeric)),
                'assertisiterable' => Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\IterableType(Type::mixed(), Type::mixed(), null)),
                'assertiscallable' => Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\CallableType(new \Mago\Sdk\Analyzer\Type\CallableSignature(false, false, [], null, null, []), null)),
                'assertisresource' => Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ResourceType(false)),
                'assertisclosedresource' => Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ResourceType(true)),
                'assertislist' => Type::list(Type::mixed()),
                default => null,
            };
        }
        if ($expected instanceof Type && (str_starts_with($name, 'assertisnot')
            ? (!$context->types->canBeIdentical($actual, $expected)
                || ($name === 'assertisnotnumeric' && $actual->getLiteralString() !== null && !is_numeric($actual->getLiteralString())))
            : $context->types->isContainedBy($actual, $expected))) {
            $this->redundant($context, $name);
        }
    }

    private function isKnown(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType || ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Never)) {
                return false;
            }
        }
        return true;
    }

    private function singleton(Type $type): bool
    {
        return count($type->atomicTypes) === 1 && (
            $type->getLiteralString() !== null || $type->getLiteralInt() !== null || $type->getLiteralBool() !== null
            || ($type->atomicTypes[0] instanceof \Mago\Sdk\Analyzer\Type\EnumType && $type->atomicTypes[0]->case !== null)
            || ($type->atomicTypes[0] instanceof ScalarType && $type->atomicTypes[0]->refinement instanceof \Mago\Sdk\Analyzer\Type\FloatType && $type->atomicTypes[0]->refinement->kind === \Mago\Sdk\Analyzer\Type\FloatTypeKind::Literal)
            || ($type->atomicTypes[0] instanceof SimpleAtomicType && $type->atomicTypes[0]->kind === SimpleAtomicTypeKind::Null)
        );
    }

    private function redundant(NodeAnalysisContext $context, string $name): void
    {
        $this->report($context, 'redundant-assertion', "Assertion {$name}() always passes for the static argument type.");
    }

    private function report(NodeAnalysisContext $context, string $code, string $message): void
    {
        $context->report(Level::Warning, $code, Issue::at($message, new SourceLocation($context->analysis->file, $context->node->span)));
    }
}
