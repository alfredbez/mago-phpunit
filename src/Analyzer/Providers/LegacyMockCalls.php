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
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use PhpToken;
use WeakReference;

final class LegacyMockCalls implements NodeAnalysisHook
{
    private const MOCK = 'phpunit\\framework\\mockobject\\mockobject';
    private const INVOCATION_ORDER = 'PHPUnit\\Framework\\MockObject\\Rule\\InvocationOrder';
    private const INVOCATION_RULE = 'PHPUnit\\Framework\\MockObject\\Rule\\InvocationRule';
    private const CONSTRAINT = 'PHPUnit\\Framework\\Constraint\\Constraint';

    /** @var WeakReference<SourceFile>|null */
    private ?WeakReference $lastSource = null;

    /** @var array<string, Node> */
    private array $lastCallIndex = [];

    public function reset(): void
    {
        $this->lastSource = null;
        $this->lastCallIndex = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::MethodCall, NodeKind::NullSafeMethodCall];
    }

    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::SourceText,
        ];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $text = $context->source->getText($context->node);
        if (stripos($text, 'method') === false && stripos($text, 'expects') === false) {
            return;
        }
        $outerCalled = self::extractOuterMethodName($text);
        if ($outerCalled !== 'method' && $outerCalled !== 'expects') {
            return;
        }
        $source = $context->analysis->getSourceFile();
        $node = $this->findCall($source, $context->node);
        if ($node === null) {
            return;
        }
        $call = CallExpression::fromNode($source, $node);
        $called = strtolower($call->getName($source) ?? '');
        if ($called !== 'method' && $called !== 'expects') {
            return;
        }
        if (count($call->arguments) !== 1 || $call->arguments[0]->unpacked) {
            return;
        }
        if ($call->receiver === null) {
            return;
        }
        [$argType, $type] = $context->analysis->getMultipleExpressionTypes([
            $call->arguments[0]->value,
            $call->receiver,
        ]);
        if (!self::isCompatiblePlainCallArgument($context, $called, $argType)) {
            return;
        }
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
        $name = $call->getName($source);
        if ($name === null || !self::isPlainMockTarget($context->codebase, $atomic->name, $name)) {
            return;
        }
        $inTest = false;
        foreach ($source->getAncestors($node) as $ancestor) {
            if ($ancestor->kind !== NodeKind::Class_) {
                continue;
            }
            foreach ($source->getChildren($ancestor) as $child) {
                if ($child->kind !== NodeKind::LocalIdentifier) {
                    continue;
                }
                $owner = $source->getResolvedName($child)?->name;
                $inTest = self::isTestClass($context->codebase, $owner);
            }
            break;
        }
        if (!$inTest) {
            return;
        }
        $context->report(Level::Error, 'mock-call-on-plain-type', Issue::at(
            "PHPUnit {$name}() called on plain type `{$atomic->name}`.",
            new SourceLocation($context->analysis->file, $node->span),
        )->withHelp("Declare the double as {$atomic->name}&MockObject so its mocked API is known."));
    }

    private function findCall(SourceFile $source, Node $target): ?Node
    {
        if ($this->lastSource?->get() !== $source) {
            $this->lastSource = WeakReference::create($source);
            $this->lastCallIndex = [];
            foreach ($source->getNodes(NodeKind::MethodCall) as $candidate) {
                $this->lastCallIndex[$candidate->span->start . ':' . $candidate->span->end] = $candidate;
            }
            foreach ($source->getNodes(NodeKind::NullSafeMethodCall) as $candidate) {
                $this->lastCallIndex[$candidate->span->start . ':' . $candidate->span->end] = $candidate;
            }
        }

        return $this->lastCallIndex[$target->span->start . ':' . $target->span->end] ?? null;
    }

    private static function extractOuterMethodName(string $callText): ?string
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize('<?php ' . $callText),
            static fn (PhpToken $token): bool => $token->id !== T_OPEN_TAG && !$token->isIgnorable(),
        ));
        $last = count($tokens) - 1;
        if ($last < 3 || $tokens[$last]->text !== ')') {
            return null;
        }
        $depth = 0;
        $open = null;
        for ($i = $last; $i >= 0; $i--) {
            $t = $tokens[$i]->text;
            if ($t === ')') {
                $depth++;
            } elseif ($t === '(') {
                $depth--;
                if ($depth === 0) {
                    $open = $i;
                    break;
                }
            }
        }
        if ($open === null || $open < 2) {
            return null;
        }
        $operator = $tokens[$open - 2]->text;
        if ($operator !== '->' && $operator !== '?->') {
            return null;
        }

        return strtolower($tokens[$open - 1]->text);
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
