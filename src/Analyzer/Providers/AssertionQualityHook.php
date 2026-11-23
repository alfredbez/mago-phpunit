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
use Mago\Sdk\Analyzer\Type\ResourceType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\SourceFile;

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

    /** @var \WeakMap<SourceFile, array<int, array{captures: array<string, int>, references: array<string, int>, writes: array<string, list<int>>, calls: list<array{start: int, end: int, name: string, arguments: list<string>}>}>> */
    private \WeakMap $scopes;

    public function __construct(private readonly bool $functions = false, private readonly bool $sameOnly = false)
    {
        $this->scopes = new \WeakMap();
    }

    public function getTargets(): array
    {
        if ($this->functions) {
            return [NodeKind::FunctionCall];
        }
        $methods = $this->sameOnly ? ['assertSame'] : array_values(array_diff(self::METHODS, ['assertSame']));
        return array_map(
            fn(string $method) => MethodTarget::exact('PHPUnit\\Framework\\Assert', $method), $methods,
        );
    }

    public function getRequirements(): array
    {
        if ($this->sameOnly) {
            return [FileAnalysisRequirement::SourceText, FileAnalysisRequirement::ArgumentTypes];
        }
        return $this->functions
            ? [FileAnalysisRequirement::SourceText, FileAnalysisRequirement::TargetSubtree]
            : [FileAnalysisRequirement::SourceText, FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::ArgumentTypes, FileAnalysisRequirement::ExpressionTypes];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        $node = $context->node;
        if ($this->sameOnly) {
            $text = $source->getText($node);
            $open = strpos($text, '(');
            $arguments = $open === false ? $text : substr($text, $open + 1);
            // Native argument types precede assertion narrowing.
            $expected = $context->argumentTypes[0] ?? null;
            $actual = $context->argumentTypes[1] ?? null;
            if (!($expected instanceof Type && $actual instanceof Type
                && $this->singleton($expected) && $this->singleton($actual))
                && stripos($arguments, 'count') === false
                && !str_contains($arguments, ':')) {
                return;
            }
            $source = $context->analysis->getSourceFile();
            $node = $this->findCall($source, $node);
        }
        $this->analyzeCall($context, $source, $node);
    }

    private function findCall(SourceFile $source, Node $target): Node
    {
        foreach ($source->getNodes($target->kind) as $node) {
            if ($node->span->start === $target->span->start && $node->span->end === $target->span->end) {
                return $node;
            }
        }
        throw new \LogicException('The native assertion target is absent from its complete source snapshot.');
    }

    private function analyzeCall(NodeAnalysisContext $context, SourceFile $source, Node $node): void
    {
        $call = CallExpression::fromNode($source, $node);
        $name = strtolower($call->getName($source) ?? '');
        if ($this->functions) {
            $resolvedNames = $source->getResolvedNames($call->callee);
            $resolved = $resolvedNames[0]->name ?? $name;
            if (!str_starts_with(strtolower(ltrim($resolved, '\\')), 'phpunit\\framework\\')) {
                return;
            }
            $name = strtolower($resolved);
        }
        $name = substr($name, (int) strrpos('\\' . $name, '\\'));
        if (!in_array($name, array_map(strtolower(...), self::METHODS), true)) {
            return;
        }
        $argumentTypes = $this->functions
            ? $context->analysis->getMultipleExpressionTypes(array_map(static fn($argument) => $argument->value, $call->arguments))
            : $context->argumentTypes;
        $arguments = [];
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked) {
                return;
            }
            $arguments[$argument->name ?? $argument->index] = [
                $argumentTypes[$argument->index] ?? null,
                $argument->value,
            ];
        }
        $actualPosition = in_array($name, ['assertsame', 'assertinstanceof'], true) ? 1 : 0;
        [$actual, $actualNode] = $arguments['actual'] ?? $arguments['object'] ?? $arguments['array'] ?? $arguments[$actualPosition] ?? [null, null];
        if ($name === 'assertsame' && $actualNode !== null) {
            $count = CallExpression::fromExpression($source, $actualNode);
            $countName = $count?->getName($source);
            if ($count !== null && strtolower(ltrim($countName ?? '', '\\')) === 'count') {
                $isCount = false;
                if ($count->isMethod() && $count->receiver !== null && $count->arguments === []) {
                    $receiver = $context->analysis->getExpressionType($count->receiver);
                    $isCount = $receiver !== null && $context->types->isContainedBy($receiver, Type::namedObject('Countable'));
                }
                if ($count->isFunction() && count($count->arguments) === 1) {
                    $names = $source->getResolvedNames($count->callee);
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
                $this->redundant($context, $source, $name, $actualNode, $actual);
            }
            return;
        }
        if ($name === 'assertsame') {
            [$first, $expectedNode] = $arguments['expected'] ?? $arguments[0] ?? [null, null];
            if ($first instanceof Type && $this->singleton($first) && $this->singleton($actual) && $context->types->equals($first, $actual)) {
                if ($expectedNode !== null && $this->hasMutableFact($context, $source, $expectedNode, $first)) {
                    return;
                }
                $this->redundant($context, $source, $name, $actualNode, $actual);
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
            $this->redundant($context, $source, $name, $actualNode, $actual);
        }
    }

    private function hasMutableFact(NodeAnalysisContext $context, SourceFile $source, Node $actual, Type $type): bool
    {
        $identity = preg_replace('/\s+/', '', $source->getText($actual));
        $property = $source->getDescendants($actual, NodeKind::PropertyAccess) !== []
            || $source->getDescendants($actual, NodeKind::StaticPropertyAccess) !== []
            || in_array($actual->kind, [NodeKind::PropertyAccess, NodeKind::StaticPropertyAccess], true);
        $resource = false;
        foreach ($type->atomicTypes as $atomic) {
            $resource = $resource || $atomic instanceof ResourceType;
        }
        if (!$property && !$resource && preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*$/D', $identity) !== 1) {
            return false;
        }
        $source = $context->analysis->getSourceFile();
        // Targeted snapshots renumber nodes and omit their enclosing statements.
        $scope = null;
        foreach ($source->getNodes($context->node->kind) as $node) {
            if ($node->span->start === $context->node->span->start && $node->span->end === $context->node->span->end) {
                $scope = $this->scope($source, $node);
                break;
            }
        }
        if ($scope === null) {
            return false;
        }
        $facts = $this->scopes[$source] ?? [];
        $facts[$scope->id] ??= $this->indexScope($source, $scope);
        $this->scopes[$source] = $facts;
        $facts = $facts[$scope->id];
        $capture = ($resource ? $facts['captures'] : $facts['references'])[$identity] ?? null;
        if (!$property && !$resource && $capture === null) {
            return false;
        }
        $before = $context->node->span->start;
        $after = $capture ?? $scope->span->start;
        foreach ($facts['writes'][$identity] ?? [] as $write) {
            if ($write < $before) {
                $after = max($after, $write);
            }
        }
        foreach ($facts['calls'] as $call) {
            if ($call['start'] >= $before) {
                break;
            }
            if ($call['start'] < $after || $call['end'] > $before) {
                continue;
            }
            if ($property || $capture !== null || ($resource && (
                in_array($identity, $call['arguments'], true)
                || in_array($call['name'], ['fclose', 'pclose', 'gzclose', 'bzclose', 'closedir', 'socket_close'], true)
            ))) {
                return true;
            }
        }
        return false;
    }

    private function scope(SourceFile $source, Node $node): ?Node
    {
        foreach ($source->getAncestors($node) as $ancestor) {
            if (in_array($ancestor->kind, [NodeKind::Method, NodeKind::Function, NodeKind::Closure, NodeKind::ArrowFunction, NodeKind::Program], true)) {
                return $ancestor;
            }
        }
        return null;
    }

    /** @return array{captures: array<string, int>, references: array<string, int>, writes: array<string, list<int>>, calls: list<array{start: int, end: int, name: string, arguments: list<string>}>} */
    private function indexScope(SourceFile $source, Node $scope): array
    {
        $facts = ['captures' => [], 'references' => [], 'writes' => [], 'calls' => []];
        foreach ($source->getDescendants($scope) as $node) {
            if ($node->kind === NodeKind::ClosureUseClauseVariable) {
                $closure = $this->scope($source, $node);
                if ($closure === null || ($closure->id !== $scope->id && $this->scope($source, $closure)?->id !== $scope->id)) {
                    continue;
                }
                $text = preg_replace('/\s+/', '', $source->getText($node));
                $variable = ltrim($text, '&');
                $facts['captures'][$variable] ??= $node->span->start;
                if (str_starts_with($text, '&')) {
                    $facts['references'][$variable] ??= $node->span->start;
                }
                continue;
            }
            if (!in_array($node->kind, [NodeKind::Assignment, NodeKind::FunctionCall, NodeKind::MethodCall, NodeKind::StaticMethodCall, NodeKind::NullSafeMethodCall], true)
                || $this->scope($source, $node)?->id !== $scope->id) {
                continue;
            }
            if ($node->kind === NodeKind::Assignment) {
                $target = $source->getChildren($node)[0] ?? null;
                if ($target !== null) {
                    $identity = preg_replace('/\s+/', '', $source->getText($target));
                    $facts['writes'][$identity][] = $node->span->end;
                }
                continue;
            }
            if (!in_array($node->kind, [NodeKind::FunctionCall, NodeKind::MethodCall, NodeKind::StaticMethodCall, NodeKind::NullSafeMethodCall], true)) {
                continue;
            }
            $call = CallExpression::fromNode($source, $node);
            $arguments = [];
            foreach ($call->arguments as $argument) {
                $arguments[] = preg_replace('/\s+/', '', $source->getText($argument->value));
            }
            $facts['calls'][] = [
                'start' => $node->span->start,
                'end' => $node->span->end,
                'name' => strtolower(ltrim($call->getName($source) ?? '', '\\')),
                'arguments' => $arguments,
            ];
        }
        usort($facts['calls'], static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        return $facts;
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

    private function redundant(NodeAnalysisContext $context, SourceFile $source, string $name, ?Node $actual, Type $type): void
    {
        if ($actual !== null && $this->hasMutableFact($context, $source, $actual, $type)) {
            return;
        }
        $this->report($context, 'redundant-assertion', "Assertion {$name}() always passes for the static argument type.");
    }

    private function report(NodeAnalysisContext $context, string $code, string $message): void
    {
        $context->report(Level::Warning, $code, Issue::at($message, new SourceLocation($context->analysis->file, $context->node->span)));
    }
}
