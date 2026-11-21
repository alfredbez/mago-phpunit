<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\TemplateParameter;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Validates PHPUnit MockBuilder::onlyMethods()/addMethods() method lists and
 * InvocationStubber/InvocationMocker willReturn()/willReturnOnConsecutiveCalls()
 * values against the mocked method's native declared return type.
 */
final class MockBuilderAndExpectationHook implements MethodCallAnalysisHook
{
    public const UNKNOWN_MOCKED_METHOD = 'phpunit-unknown-mocked-method';
    public const INVALID_MOCK_BUILDER_METHOD = 'phpunit-invalid-mock-builder-method';
    public const INCOMPATIBLE_MOCK_RETURN = 'phpunit-incompatible-mock-return';

    private const MOCK_BUILDER_CLASSES = [
        'phpunit\\framework\\mockobject\\mockbuilder' => true,
        'phpunit\\framework\\mockobject\\testdoublebuilder' => true,
        'phpunit\\framework\\mockobject\\teststubbuilder' => true,
    ];

    private const MOCK_CLASSES = [
        'phpunit\\framework\\mockobject\\mockobject' => true,
        'phpunit\\framework\\mockobject\\stub' => true,
        'phpunit\\framework\\mockobject\\rule\\invocationorder' => true,
        'phpunit\\framework\\mockobject\\builder\\invocationmocker' => true,
        'phpunit\\framework\\mockobject\\builder\\invocationstubber' => true,
        'phpunit\\framework\\mockobject\\invocationstubber' => true,
    ];

    public function getTargets(): array
    {
        return [
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\MockBuilder', 'onlyMethods'),
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\TestDoubleBuilder', 'onlyMethods'),
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\MockBuilder', 'addMethods'),
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\InvocationStubber', 'willReturn'),
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\InvocationStubber', 'willReturnOnConsecutiveCalls'),
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\Builder\\InvocationStubber', 'willReturn'),
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\Builder\\InvocationStubber', 'willReturnOnConsecutiveCalls'),
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\Builder\\InvocationMocker', 'willReturn'),
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\Builder\\InvocationMocker', 'willReturnOnConsecutiveCalls'),
        ];
    }

    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::ReceiverType,
            FileAnalysisRequirement::ArgumentTypes,
            FileAnalysisRequirement::SourceText,
            FileAnalysisRequirement::TargetSubtree,
            FileAnalysisRequirement::ExpressionTypes,
        ];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $called = strtolower($this->calledMethodName($context->source, $context->node) ?? '');
        if ($called === 'onlymethods' || $called === 'addmethods') {
            $this->checkMockBuilderMethods($context, $called);

            return;
        }

        if ($called === 'willreturn' || $called === 'willreturnonconsecutivecalls') {
            $this->checkWillReturn($context, $called);
        }
    }

    private function checkMockBuilderMethods(NodeAnalysisContext $context, string $called): void
    {
        $methodsArg = $context->argumentTypes[0] ?? null;
        if (!$methodsArg instanceof Type || !$context->receiverType instanceof Type) {
            return;
        }

        $targetClass = self::extractMockBuilderTarget($context->receiverType);
        if ($targetClass === null || strtolower($targetClass) === 'stdclass') {
            return;
        }

        $meta = $context->codebase->getClassLike($targetClass);
        if (!$meta instanceof ClassLikeMetadata || $meta->hasIncompleteHierarchy()) {
            return;
        }

        $literals = self::extractStringListLiterals($methodsArg);
        if ($literals === []) {
            return;
        }

        $checks = [];
        foreach ($literals as $methodName) {
            $checks[] = new MemberIdentifier($targetClass, $methodName);
        }
        $exists = $context->codebase->checkMultipleMethodsExist($checks);

        foreach ($literals as $index => $methodName) {
            $methodExists = $exists[$index] ?? false;
            if ($called === 'onlymethods' && !$methodExists) {
                $context->report(
                    Level::Error,
                    self::UNKNOWN_MOCKED_METHOD,
                    Issue::at(
                        "Trying to configure undefined method `{$targetClass}::{$methodName}()` with MockBuilder::onlyMethods().",
                        new SourceLocation($context->analysis->file, $context->node->span),
                    ),
                );
            } elseif ($called === 'addmethods' && $methodExists) {
                $context->report(
                    Level::Error,
                    self::INVALID_MOCK_BUILDER_METHOD,
                    Issue::at(
                        "Trying to add already-existing method `{$targetClass}::{$methodName}()` with MockBuilder::addMethods(); use onlyMethods() instead.",
                        new SourceLocation($context->analysis->file, $context->node->span),
                    ),
                );
            }
        }
    }

    private function checkWillReturn(NodeAnalysisContext $context, string $called): void
    {
        if ($context->argumentTypes === []) {
            return;
        }

        $chain = $this->resolveMockMethodChain($context);
        if ($chain === null) {
            return;
        }

        [$targets, $methodName] = $chain;
        if (str_contains($methodName, '::')) {
            return;
        }

        $metadataList = $context->codebase->getMultipleClassLikes($targets);
        $magicChecks = [];
        foreach ($metadataList as $index => $meta) {
            if (!$meta instanceof ClassLikeMetadata || $meta->hasIncompleteHierarchy() || $meta->mixins !== []) {
                return;
            }
            $magicChecks[] = new MemberIdentifier($targets[$index], '__call');
        }
        foreach ($context->codebase->checkMultipleMethodsExist($magicChecks) as $hasMagic) {
            if ($hasMagic) {
                return;
            }
        }

        $methodQueries = [];
        foreach ($targets as $target) {
            $methodQueries[] = new MemberIdentifier($target, $methodName);
        }
        $methods = $context->codebase->getMultipleDeclaringMethods($methodQueries);
        $declaredReturn = null;
        foreach ($methods as $methodMeta) {
            if ($methodMeta === null) {
                continue;
            }
            if ($methodMeta->declaredReturnType?->type instanceof Type) {
                $declaredReturn = $methodMeta->declaredReturnType->type;
                break;
            }
        }

        if (!$declaredReturn instanceof Type) {
            return;
        }

        $targetLabel = implode('&', $targets);
        $displayCall = $called === 'willreturn' ? 'willReturn' : 'willReturnOnConsecutiveCalls';

        if (self::isNever($declaredReturn) || ($called === 'willreturn' && self::isVoid($declaredReturn))) {
            $context->report(
                Level::Error,
                self::INCOMPATIBLE_MOCK_RETURN,
                Issue::at(
                    "Method `{$targetLabel}::{$methodName}()` has return type `{$declaredReturn}` and cannot be configured with {$displayCall}().",
                    new SourceLocation($context->analysis->file, $context->node->span),
                ),
            );

            return;
        }

        if (self::isVoid($declaredReturn)) {
            return;
        }

        if (!self::isCheckableDeclaredReturn($declaredReturn)) {
            return;
        }

        $call = CallExpression::fromNode($context->source, $context->node);
        $expected = self::widenFloatForPhp($declaredReturn);
        foreach ($context->argumentTypes as $index => $argType) {
            if (!$argType instanceof Type) {
                continue;
            }

            $callArg = $call->arguments[$index] ?? null;
            $candidateTypes = $callArg !== null && $callArg->unpacked
                ? self::extractUnpackedArgumentTypes($argType)
                : [$argType];

            foreach ($candidateTypes as $candidateType) {
                if (self::containsMixedOrTemplate($candidateType)) {
                    continue;
                }

                if ($context->types->isContainedBy($candidateType, $expected)) {
                    continue;
                }

                if ($context->types->canBeIdentical($candidateType, $expected)) {
                    continue;
                }

                $argNumber = $index + 1;
                $context->report(
                    Level::Error,
                    self::INCOMPATIBLE_MOCK_RETURN,
                    Issue::at(
                        "Argument #{$argNumber} of {$displayCall}() provides `{$candidateType}` for `{$targetLabel}::{$methodName}()`, whose declared return type is `{$declaredReturn}`.",
                        new SourceLocation($context->analysis->file, $context->node->span),
                    ),
                );
            }
        }
    }

    /**
     * Walks a fluent stubbing chain backwards (`$mock->expects(...)->method('foo')->with(...)->willReturn(...)`)
     * to recover the mocked target class/interface(s) and literal method name.
     *
     * @return null|array{0: non-empty-list<string>, 1: string}
     */
    private function resolveMockMethodChain(NodeAnalysisContext $context): ?array
    {
        $source = $context->source;
        $current = $context->node;
        $methodName = null;
        $mockReceiver = null;

        for ($depth = 0; $depth < 16; ++$depth) {
            $rawReceiver = $source->getChildren($current)[0] ?? null;
            $receiver = $this->unwrap($source, $rawReceiver);
            if ($rawReceiver === null || $receiver === null) {
                break;
            }

            if ($receiver->kind !== NodeKind::MethodCall) {
                $mockReceiver = $rawReceiver;
                break;
            }

            $name = strtolower($this->calledMethodName($source, $receiver) ?? '');
            if ($name === 'method' && $methodName === null) {
                $firstArg = $this->methodConstraintArgument($source, $receiver);
                if ($firstArg === null) {
                    return null;
                }
                $argType = $context->analysis->getExpressionType($firstArg);
                $literal = $argType?->getLiteralString() ?? $this->literalStringFromAst($source, $firstArg);
                if ($literal === null || $literal === '') {
                    return null;
                }
                $methodName = $literal;
            }

            $current = $receiver;
        }

        if ($methodName === null || $mockReceiver === null) {
            return null;
        }

        $receiverType = $context->analysis->getExpressionType($mockReceiver)
            ?? $context->analysis->getExpressionType($this->unwrap($source, $mockReceiver) ?? $mockReceiver);
        if (!$receiverType instanceof Type) {
            return null;
        }

        $groups = self::extractDoubleGroups($receiverType);
        if (count($groups) !== 1) {
            return null;
        }

        return [$groups[0], $methodName];
    }

    private static function extractMockBuilderTarget(Type $receiverType): ?string
    {
        if (count($receiverType->atomicTypes) !== 1) {
            return null;
        }

        $atomic = $receiverType->atomicTypes[0];
        if (!$atomic instanceof NamedObjectType || !isset(self::MOCK_BUILDER_CLASSES[strtolower($atomic->name)])) {
            return null;
        }

        $param = $atomic->parameters[0] ?? null;
        if (!$param instanceof Type || count($param->atomicTypes) !== 1) {
            return null;
        }

        $targetAtomic = $param->atomicTypes[0];
        if (!$targetAtomic instanceof NamedObjectType) {
            return null;
        }

        return $targetAtomic->name;
    }

    /**
     * @return list<string>
     */
    private static function extractStringListLiterals(Type $type): array
    {
        if (count($type->atomicTypes) !== 1) {
            return [];
        }

        $atomic = $type->atomicTypes[0];
        $literals = [];
        if ($atomic instanceof ListType) {
            if ($atomic->knownElements === null) {
                return [];
            }
            foreach ($atomic->knownElements as $element) {
                $lit = $element->type->getLiteralString();
                if ($lit !== null && $lit !== '') {
                    $literals[] = $lit;
                }
            }
        } elseif ($atomic instanceof KeyedArrayType) {
            if ($atomic->knownItems === null) {
                return [];
            }
            foreach ($atomic->knownItems as $item) {
                $lit = $item->type->getLiteralString();
                if ($lit !== null && $lit !== '') {
                    $literals[] = $lit;
                }
            }
        }

        return array_values(array_unique($literals));
    }

    /**
     * @return list<non-empty-list<string>>
     */
    private static function extractDoubleGroups(Type $receiverType): array
    {
        $groups = [];
        foreach ($receiverType->atomicTypes as $atomic) {
            if (!$atomic instanceof NamedObjectType) {
                continue;
            }

            $members = [$atomic->name];
            foreach ($atomic->intersections ?? [] as $intersection) {
                if ($intersection instanceof NamedObjectType) {
                    $members[] = $intersection->name;
                }
            }

            $hasDoubleMarker = false;
            $targets = [];
            foreach ($members as $member) {
                $lower = strtolower($member);
                if ($lower === 'stdclass') {
                    continue 2;
                }
                if (isset(self::MOCK_CLASSES[$lower])) {
                    $hasDoubleMarker = true;
                    continue;
                }
                $targets[$lower] = $member;
            }

            if ($hasDoubleMarker && $targets !== []) {
                $groups[] = array_values($targets);
            }
        }

        return $groups;
    }

    /**
     * @return list<Type>
     */
    private static function extractUnpackedArgumentTypes(Type $type): array
    {
        if (count($type->atomicTypes) !== 1) {
            return [];
        }

        $atomic = $type->atomicTypes[0];
        $types = [];
        if ($atomic instanceof ListType) {
            if ($atomic->knownElements === null) {
                return [];
            }
            foreach ($atomic->knownElements as $element) {
                if (!$element->optional) {
                    $types[] = $element->type;
                }
            }
        } elseif ($atomic instanceof KeyedArrayType) {
            if ($atomic->knownItems === null) {
                return [];
            }
            foreach ($atomic->knownItems as $item) {
                if (!$item->optional) {
                    $types[] = $item->type;
                }
            }
        }

        return $types;
    }

    private static function isVoid(Type $type): bool
    {
        if (count($type->atomicTypes) !== 1) {
            return false;
        }

        $atomic = $type->atomicTypes[0];

        return $atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Void;
    }

    private static function isNever(Type $type): bool
    {
        if (count($type->atomicTypes) !== 1) {
            return false;
        }

        $atomic = $type->atomicTypes[0];

        return $atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Never;
    }

    private static function isCheckableDeclaredReturn(Type $type): bool
    {
        if ($type->atomicTypes === []) {
            return false;
        }

        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType || $atomic instanceof TemplateParameter) {
                return false;
            }
            if ($atomic instanceof NamedObjectType) {
                $lower = strtolower($atomic->name);
                if ($lower === 'self' || $lower === 'static' || $lower === 'parent') {
                    return false;
                }
            }
        }

        return true;
    }

    private static function containsMixedOrTemplate(Type $type): bool
    {
        if ($type->atomicTypes === []) {
            return true;
        }

        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType || $atomic instanceof TemplateParameter) {
                return true;
            }
        }

        return false;
    }

    private static function widenFloatForPhp(Type $type): Type
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::Float) {
                return Type::union($type, Type::int());
            }
        }

        return $type;
    }

    private function calledMethodName(SourceFile $source, Node $call): ?string
    {
        $children = $source->getChildren($call);
        $member = $children[1] ?? null;
        if ($member === null) {
            return null;
        }
        $selector = $source->getChildren($member)[0] ?? null;
        if ($selector === null || $selector->kind !== NodeKind::LocalIdentifier) {
            return null;
        }

        return $source->getText($selector);
    }

    private function methodConstraintArgument(SourceFile $source, Node $call): ?Node
    {
        $expr = CallExpression::fromNode($source, $call);
        foreach ($expr->arguments as $arg) {
            if ($arg->name === 'constraint' && !$arg->unpacked) {
                return $arg->value;
            }
        }

        $first = $expr->arguments[0] ?? null;
        if ($first !== null && $first->name === null && !$first->unpacked) {
            return $first->value;
        }

        return null;
    }

    private function literalStringFromAst(SourceFile $source, Node $node): ?string
    {
        $node = $this->unwrap($source, $node);
        if ($node === null) {
            return null;
        }

        $literal = $source->getLiteralString($node);
        if ($literal !== null) {
            return $literal;
        }

        $text = $source->getText($node);
        if (strlen($text) >= 2 && (($text[0] === "'" && $text[-1] === "'") || ($text[0] === '"' && $text[-1] === '"'))) {
            return substr($text, 1, -1);
        }

        return null;
    }

    private function unwrap(SourceFile $source, ?Node $node): ?Node
    {
        while (
            $node !== null
            && (
                $node->kind === NodeKind::Expression
                || $node->kind === NodeKind::Call
                || $node->kind === NodeKind::Parenthesized
                || $node->kind === NodeKind::Literal
            )
        ) {
            $node = $source->getChildren($node)[0] ?? null;
        }

        return $node;
    }
}
