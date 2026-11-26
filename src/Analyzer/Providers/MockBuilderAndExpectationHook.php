<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AliasType;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\DerivedType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ReferenceType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;
use Mago\Sdk\Analyzer\Type\VariableType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use WeakReference;

/**
 * Validates PHPUnit MockBuilder::onlyMethods()/addMethods() method lists and
 * InvocationStubber/InvocationMocker willReturn()/willReturnOnConsecutiveCalls()
 * values against the mocked method's native declared return type.
 *
 * @internal
 */
final class MockBuilderAndExpectationHook implements MethodCallAnalysisHook
{
    public const UNKNOWN_MOCKED_METHOD = 'phpunit-unknown-mocked-method';
    public const INVALID_MOCK_BUILDER_METHOD = 'phpunit-invalid-mock-builder-method';
    public const INCOMPATIBLE_MOCK_RETURN = 'phpunit-incompatible-mock-return';

    private const STUB_INTERFACE = 'PHPUnit\\Framework\\MockObject\\Stub\\Stub';
    private const EXCEPTION_STUB = 'PHPUnit\\Framework\\MockObject\\Stub\\Exception';
    private const RETURN_STUB = 'phpunit\\framework\\mockobject\\stub\\returnstub';
    private const RETURN_CALLBACK = 'phpunit\\framework\\mockobject\\stub\\returncallback';
    private const RETURN_SELF = 'phpunit\\framework\\mockobject\\stub\\returnself';
    private const RETURN_ARGUMENT = 'phpunit\\framework\\mockobject\\stub\\returnargument';
    private const RETURN_VALUE_MAP = 'phpunit\\framework\\mockobject\\stub\\returnvaluemap';
    private const RETURN_REFERENCE = 'phpunit\\framework\\mockobject\\stub\\returnreference';
    private const CONSECUTIVE_CALLS = 'phpunit\\framework\\mockobject\\stub\\consecutivecalls';

    private const MAX_LOCAL_RESOLUTION_DEPTH = 4;
    private const MAX_CONSECUTIVE_NESTING_DEPTH = 4;

    private const STUB_IMPLEMENTATIONS = [
        'phpunit\\framework\\mockobject\\stub\\stub' => true,
        'phpunit\\framework\\mockobject\\stub\\exception' => true,
        'phpunit\\framework\\mockobject\\stub\\returnstub' => true,
        'phpunit\\framework\\mockobject\\stub\\returncallback' => true,
        'phpunit\\framework\\mockobject\\stub\\returnargument' => true,
        'phpunit\\framework\\mockobject\\stub\\returnself' => true,
        'phpunit\\framework\\mockobject\\stub\\returnvaluemap' => true,
        'phpunit\\framework\\mockobject\\stub\\returnreference' => true,
        'phpunit\\framework\\mockobject\\stub\\consecutivecalls' => true,
    ];

    private const BY_VALUE_STUB_CLASSES = [
        self::RETURN_STUB => true,
        self::RETURN_CALLBACK => true,
        self::RETURN_ARGUMENT => true,
        self::RETURN_VALUE_MAP => true,
        self::CONSECUTIVE_CALLS => true,
        'phpunit\\framework\\mockobject\\stub\\exception' => true,
    ];

    private const BY_VALUE_TESTCASE_HELPERS = [
        'returnvalue' => true,
        'returncallback' => true,
        'returnargument' => true,
        'returnvaluemap' => true,
        'onconsecutivecalls' => true,
        'throwexception' => true,
    ];

    /** @var WeakReference<SourceFile>|null */
    private ?WeakReference $lastScopeSource = null;

    /**
     * @var array<int, array{
     *     dynamicWrite: bool,
     *     ambiguous: array<string, true>,
     *     writes: array<string, list<array{assign: Node, rhs: Node, containerId: ?int}>>,
     *     callArgOffsets: array<string, list<int>>,
     * }>
     */
    private array $lastScopeLocalIndex = [];

    public function reset(): void
    {
        $this->lastScopeSource = null;
        $this->lastScopeLocalIndex = [];
    }

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
        $declaringMethod = null;
        foreach ($methods as $methodMeta) {
            if ($methodMeta === null) {
                continue;
            }
            if ($methodMeta->declaredReturnType?->type instanceof Type) {
                $declaredReturn = $methodMeta->declaredReturnType->type;
                $declaringMethod = $methodMeta;
                break;
            }
        }

        if (!$declaredReturn instanceof Type || !$declaringMethod instanceof FunctionLikeMetadata) {
            return;
        }

        $targetLabel = implode('&', $targets);
        $displayCall = $called === 'willreturn' ? 'willReturn' : 'willReturnOnConsecutiveCalls';
        $isConsecutive = $called === 'willreturnonconsecutivecalls';

        if (self::isNever($declaredReturn)) {
            if ($isConsecutive && $this->allArgumentsThrow($context, $targets, $declaringMethod)) {
                return;
            }
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

        if ($called === 'willreturn' && self::isVoid($declaredReturn)) {
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

        $resolvedReturn = $this->resolveRelativeObjectTypes(
            $context,
            $declaredReturn,
            $targets,
            $declaringMethod->identifier->class,
        );
        if (!self::isCheckableDeclaredReturn($resolvedReturn)) {
            return;
        }

        $call = CallExpression::fromNode($context->source, $context->node);
        $expected = self::widenFloatForPhp($resolvedReturn);
        foreach ($context->argumentTypes as $index => $argType) {
            if (!$argType instanceof Type) {
                continue;
            }

            $callArg = $call->arguments[$index] ?? null;
            $candidates = $this->extractCallCandidates(
                $context,
                $argType,
                $context->source,
                $callArg?->value,
                $callArg !== null && $callArg->unpacked,
            );

            foreach ($candidates as $candidate) {
                $candidateType = $candidate['type'];
                if (self::containsMixedOrTemplate($candidateType)) {
                    continue;
                }

                // ConsecutiveCalls::invoke() unwraps any PHPUnit Stub at call time.
                if ($isConsecutive && self::containsStubObject($context, $candidateType)) {
                    if (!self::isStubObject($context, $candidateType)) {
                        continue;
                    }
                    $outcome = $this->resolveConsecutiveStubOutcome(
                        $context,
                        $candidateType,
                        $candidate['source'],
                        $candidate['node'],
                        $targets,
                        $declaringMethod,
                    );
                    if ($outcome['kind'] !== 'payloads') {
                        continue;
                    }
                    foreach ($outcome['items'] as $item) {
                        if ($this->isCompatiblePayload($context, $item['type'], $expected, $item['requireContainment'])) {
                            continue;
                        }
                        $argNumber = $index + 1;
                        $providedLabel = $item['label'] ?? (string) $item['type'];
                        $context->report(
                            Level::Error,
                            self::INCOMPATIBLE_MOCK_RETURN,
                            Issue::at(
                                "Argument #{$argNumber} of {$displayCall}() provides `{$providedLabel}` for `{$targetLabel}::{$methodName}()`, whose declared return type is `{$declaredReturn}`.",
                                new SourceLocation($context->analysis->file, $context->node->span),
                            ),
                        );
                        break;
                    }
                    continue;
                }

                if ($this->isCompatiblePayload($context, $candidateType, $expected, false)) {
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

    /**
     * @return list<array{type: Type, source: SourceFile, node: ?Node}>
     */
    private function extractCallCandidates(
        NodeAnalysisContext $context,
        Type $argType,
        SourceFile $source,
        ?Node $argValueNode,
        bool $unpacked,
    ): array {
        if (!$unpacked) {
            return [[
                'type' => $argType,
                'source' => $source,
                'node' => $argValueNode,
            ]];
        }

        if ($argValueNode !== null) {
            $resolvedArray = $this->resolveLocalExpression($context, $source, $argValueNode);
            $arraySource = $resolvedArray !== null ? $resolvedArray[0] : $source;
            $arrayNode = $resolvedArray !== null ? $resolvedArray[1] : $argValueNode;
            $literalNodes = $this->extractUnpackedLiteralArrayNodes($arraySource, $arrayNode);
            if ($literalNodes !== []) {
                $candidates = [];
                $allTyped = true;
                foreach ($literalNodes as $node) {
                    $nodeType = $this->expressionType($context, $arraySource, $node);
                    if ($nodeType === null) {
                        $allTyped = false;
                        break;
                    }
                    $candidates[] = [
                        'type' => $nodeType,
                        'source' => $arraySource,
                        'node' => $node,
                    ];
                }
                if ($allTyped) {
                    return $candidates;
                }
            }
        }

        $candidates = [];
        foreach (self::extractUnpackedArgumentTypes($argType) as $candidateType) {
            $candidates[] = [
                'type' => $candidateType,
                'source' => $source,
                'node' => null,
            ];
        }

        return $candidates;
    }

    /**
     * @param non-empty-list<string> $targets
     */
    private function allArgumentsThrow(
        NodeAnalysisContext $context,
        array $targets,
        FunctionLikeMetadata $declaringMethod,
    ): bool {
        if ($context->argumentTypes === []) {
            return false;
        }

        $call = CallExpression::fromNode($context->source, $context->node);
        foreach ($context->argumentTypes as $index => $argType) {
            if (!$argType instanceof Type) {
                return false;
            }
            $callArg = $call->arguments[$index] ?? null;
            $candidates = $this->extractCallCandidates(
                $context,
                $argType,
                $context->source,
                $callArg?->value,
                $callArg !== null && $callArg->unpacked,
            );
            if ($candidates === []) {
                return false;
            }
            foreach ($candidates as $candidate) {
                $candidateType = $candidate['type'];
                if (self::isThrowingStub($context, $candidateType)) {
                    continue;
                }
                if (self::isStubObject($context, $candidateType)) {
                    $outcome = $this->resolveConsecutiveStubOutcome(
                        $context,
                        $candidateType,
                        $candidate['source'],
                        $candidate['node'],
                        $targets,
                        $declaringMethod,
                    );
                    if ($outcome['kind'] === 'throws') {
                        continue;
                    }
                }

                return false;
            }
        }

        return true;
    }

    private static function isThrowingStub(NodeAnalysisContext $context, Type $type): bool
    {
        $allExceptionStub = true;
        foreach ($type->atomicTypes as $atomic) {
            if (!$atomic instanceof NamedObjectType || strtolower($atomic->name) !== 'phpunit\\framework\\mockobject\\stub\\exception') {
                $allExceptionStub = false;
                break;
            }
        }
        if ($allExceptionStub) {
            return true;
        }

        return $context->types->isContainedBy($type, Type::namedObject(self::EXCEPTION_STUB));
    }

    private static function isStubObject(NodeAnalysisContext $context, Type $type): bool
    {
        $allKnown = true;
        foreach ($type->atomicTypes as $atomic) {
            if (!$atomic instanceof NamedObjectType || !isset(self::STUB_IMPLEMENTATIONS[strtolower($atomic->name)])) {
                $allKnown = false;
                break;
            }
        }
        if ($allKnown) {
            return true;
        }

        return $context->types->isContainedBy($type, Type::namedObject(self::STUB_INTERFACE));
    }

    private static function containsStubObject(NodeAnalysisContext $context, Type $type): bool
    {
        $stubInterface = Type::namedObject(self::STUB_INTERFACE);
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof NamedObjectType && isset(self::STUB_IMPLEMENTATIONS[strtolower($atomic->name)])) {
                return true;
            }
            if ($context->types->isContainedBy(Type::fromAtomic($atomic), $stubInterface)) {
                return true;
            }
        }

        return false;
    }

    private static function isExactStubClass(Type $type, string $expectedLowerName): bool
    {
        if (count($type->atomicTypes) !== 1) {
            return false;
        }

        $atomic = $type->atomicTypes[0];

        return $atomic instanceof NamedObjectType && strtolower($atomic->name) === $expectedLowerName;
    }

    private function isCompatiblePayload(
        NodeAnalysisContext $context,
        Type $candidateType,
        Type $expected,
        bool $requireContainment,
    ): bool {
        if (self::isNever($candidateType) || self::containsMixedOrTemplate($candidateType)) {
            return true;
        }

        if ($context->types->isContainedBy($candidateType, $expected)) {
            return true;
        }

        if (!$requireContainment && $context->types->canBeIdentical($candidateType, $expected)) {
            return true;
        }

        return false;
    }

    /**
     * @param non-empty-list<string> $targets
     *
     * @return array{kind: 'throws'|'unknown'}|array{kind: 'payloads', items: non-empty-list<array{type: Type, requireContainment: bool, label?: string}>}
     */
    private function resolveConsecutiveStubOutcome(
        NodeAnalysisContext $context,
        Type $stubType,
        SourceFile $source,
        ?Node $stubNode,
        array $targets,
        FunctionLikeMetadata $declaringMethod,
        int $depth = 0,
    ): array {
        if ($depth > self::MAX_CONSECUTIVE_NESTING_DEPTH) {
            return ['kind' => 'unknown'];
        }

        if (self::isThrowingStub($context, $stubType)) {
            return ['kind' => 'throws'];
        }

        if (self::isExactStubClass($stubType, self::RETURN_SELF)) {
            return [
                'kind' => 'payloads',
                'items' => [[
                    'type' => self::buildMockTargetType($targets),
                    'requireContainment' => true,
                    'label' => implode('&', $targets),
                ]],
            ];
        }

        // ReturnReference binds by reference, so callers can mutate the variable to a valid return value before invocation.
        if (self::isExactStubClass($stubType, self::RETURN_REFERENCE)) {
            return ['kind' => 'unknown'];
        }

        $resolvedNode = $stubNode !== null ? $this->resolveLocalExpression($context, $source, $stubNode) : null;
        $nodeSource = $resolvedNode !== null ? $resolvedNode[0] : $source;
        $node = $resolvedNode !== null ? $resolvedNode[1] : $stubNode;

        if (self::isExactStubClass($stubType, self::RETURN_STUB)) {
            $valueNode = $this->extractStubArgumentNode($context, $nodeSource, $node, ['value'], 'returnvalue');
            if ($valueNode === null) {
                return ['kind' => 'unknown'];
            }

            $valueType = $this->expressionType($context, $nodeSource, $valueNode);
            if ($valueType === null) {
                return ['kind' => 'unknown'];
            }

            return [
                'kind' => 'payloads',
                'items' => [[
                    'type' => $valueType,
                    'requireContainment' => false,
                ]],
            ];
        }

        if (self::isExactStubClass($stubType, self::RETURN_CALLBACK)) {
            $callbackNode = $this->extractStubArgumentNode($context, $nodeSource, $node, ['callback'], 'returncallback');
            if ($callbackNode === null) {
                return ['kind' => 'unknown'];
            }

            $callbackType = $this->expressionType($context, $nodeSource, $callbackNode);
            if ($callbackType === null) {
                return ['kind' => 'unknown'];
            }

            $returnType = $this->resolveCallbackReturnType($context, $callbackType);
            if ($returnType === null) {
                return ['kind' => 'unknown'];
            }

            if (self::isNever($returnType)) {
                return ['kind' => 'throws'];
            }

            return [
                'kind' => 'payloads',
                'items' => [[
                    'type' => $returnType,
                    'requireContainment' => false,
                ]],
            ];
        }

        if (self::isExactStubClass($stubType, self::RETURN_ARGUMENT)) {
            return $this->resolveReturnArgumentOutcome($context, $nodeSource, $node, $targets, $declaringMethod);
        }

        if (self::isExactStubClass($stubType, self::RETURN_VALUE_MAP)) {
            return $this->resolveReturnValueMapOutcome($context, $nodeSource, $node, $declaringMethod);
        }

        if (self::isExactStubClass($stubType, self::CONSECUTIVE_CALLS)) {
            return $this->resolveNestedConsecutiveCallsOutcome($context, $nodeSource, $node, $targets, $declaringMethod, $depth + 1);
        }

        return ['kind' => 'unknown'];
    }

    /**
     * @param non-empty-list<string> $targets
     *
     * @return array{kind: 'unknown'}|array{kind: 'payloads', items: non-empty-list<array{type: Type, requireContainment: bool}>}
     */
    private function resolveReturnArgumentOutcome(
        NodeAnalysisContext $context,
        SourceFile $source,
        ?Node $stubNode,
        array $targets,
        FunctionLikeMetadata $declaringMethod,
    ): array {
        $indexNode = $this->extractStubArgumentNode($context, $source, $stubNode, ['argumentIndex', 'index'], 'returnargument');
        if ($indexNode === null) {
            return ['kind' => 'unknown'];
        }

        $index = $this->resolveLiteralInt($context, $source, $indexNode);
        if ($index === null) {
            return ['kind' => 'unknown'];
        }

        if ($index < 0) {
            return [
                'kind' => 'payloads',
                'items' => [[
                    'type' => Type::null(),
                    'requireContainment' => true,
                ]],
            ];
        }

        $parameters = $declaringMethod->parameters;
        $parameter = $parameters[$index] ?? null;
        if ($parameter === null && $parameters !== []) {
            $last = $parameters[array_key_last($parameters)];
            if ($last->flags->contains(MetadataFlags::VARIADIC)) {
                $parameter = $last;
            }
        }

        if ($parameter === null) {
            return [
                'kind' => 'payloads',
                'items' => [[
                    'type' => Type::null(),
                    'requireContainment' => true,
                ]],
            ];
        }

        $declaredParamType = $parameter->declaredType?->type;
        if (!$declaredParamType instanceof Type) {
            return ['kind' => 'unknown'];
        }

        $resolvedParamType = $this->resolveRelativeObjectTypes(
            $context,
            $declaredParamType,
            $targets,
            $declaringMethod->identifier->class,
        );
        if (self::containsMixedOrTemplate($resolvedParamType)) {
            return ['kind' => 'unknown'];
        }

        return [
            'kind' => 'payloads',
            'items' => [[
                'type' => $resolvedParamType,
                'requireContainment' => true,
            ]],
        ];
    }

    /**
     * @return array{kind: 'unknown'}|array{kind: 'payloads', items: non-empty-list<array{type: Type, requireContainment: bool}>}
     */
    private function resolveReturnValueMapOutcome(
        NodeAnalysisContext $context,
        SourceFile $source,
        ?Node $stubNode,
        FunctionLikeMetadata $declaringMethod,
    ): array {
        $mapNode = $this->extractStubArgumentNode($context, $source, $stubNode, ['valueMap'], 'returnvaluemap');
        if ($mapNode === null) {
            return ['kind' => 'unknown'];
        }

        $resolvedMap = $this->resolveLocalExpression($context, $source, $mapNode);
        $mapSource = $resolvedMap !== null ? $resolvedMap[0] : $source;
        $mapExpr = $resolvedMap !== null ? $resolvedMap[1] : $mapNode;

        $rows = $this->extractReturnValueMapRows($context, $mapSource, $mapExpr);
        if ($rows === null) {
            return ['kind' => 'unknown'];
        }

        $minParams = self::requiredParameterCount($declaringMethod);
        $items = [];
        $hasMatchingRow = false;
        $allRowAritiesKnown = true;

        foreach ($rows as $row) {
            if ($row === null) {
                $allRowAritiesKnown = false;
                continue;
            }

            if ($row['inputCount'] < $minParams) {
                continue;
            }

            $hasMatchingRow = true;
            if ($row['returnType'] !== null) {
                $items[] = [
                    'type' => $row['returnType'],
                    'requireContainment' => false,
                ];
            }
        }

        if (!$hasMatchingRow && $allRowAritiesKnown) {
            return [
                'kind' => 'payloads',
                'items' => [[
                    'type' => Type::null(),
                    'requireContainment' => false,
                ]],
            ];
        }

        if ($items === []) {
            return ['kind' => 'unknown'];
        }

        return [
            'kind' => 'payloads',
            'items' => $items,
        ];
    }

    /**
     * @return null|list<null|array{inputCount: int, returnType: ?Type}>
     */
    private function extractReturnValueMapRows(NodeAnalysisContext $context, SourceFile $source, Node $mapNode): ?array
    {
        $unwrapped = $this->unwrap($source, $mapNode);
        if ($unwrapped !== null && ($unwrapped->kind === NodeKind::Array || $unwrapped->kind === NodeKind::LegacyArray)) {
            $rowNodes = $this->extractLiteralArrayElementNodes($source, $unwrapped);
            if ($rowNodes !== null) {
                $rows = [];
                foreach ($rowNodes as $rowNode) {
                    $resolvedRow = $this->resolveLocalExpression($context, $source, $rowNode);
                    $rowSource = $resolvedRow !== null ? $resolvedRow[0] : $source;
                    $rowExpr = $resolvedRow !== null ? $resolvedRow[1] : $rowNode;
                    $rowUnwrapped = $this->unwrap($rowSource, $rowExpr);
                    if ($rowUnwrapped !== null && ($rowUnwrapped->kind === NodeKind::Array || $rowUnwrapped->kind === NodeKind::LegacyArray)) {
                        $cells = $this->extractLiteralArrayElementNodes($rowSource, $rowUnwrapped);
                        if ($cells === null) {
                            $rows[] = null;
                            continue;
                        }
                        if ($cells === []) {
                            $rows[] = ['inputCount' => -1, 'returnType' => null];
                            continue;
                        }
                        $lastCell = $cells[array_key_last($cells)];
                        $rows[] = [
                            'inputCount' => count($cells) - 1,
                            'returnType' => $this->expressionType($context, $rowSource, $lastCell),
                        ];
                        continue;
                    }

                    $rowType = $this->expressionType($context, $rowSource, $rowExpr);
                    $rows[] = $rowType !== null ? self::extractReturnValueMapRowFromType($rowType) : null;
                }

                return $rows;
            }
        }

        if ($unwrapped !== null && $unwrapped->kind === NodeKind::DirectVariable && $this->isReferenceAliasedLocal($context, $source, $unwrapped)) {
            return null;
        }

        $mapType = $this->expressionType($context, $source, $mapNode);
        if ($mapType === null) {
            return null;
        }

        $rowTypes = self::extractKnownArrayElementTypes($mapType);
        if ($rowTypes === null) {
            return null;
        }

        $rows = [];
        foreach ($rowTypes as $rowType) {
            $rows[] = self::extractReturnValueMapRowFromType($rowType);
        }

        return $rows;
    }

    /**
     * @return null|array{inputCount: int, returnType: ?Type}
     */
    private static function extractReturnValueMapRowFromType(Type $rowType): ?array
    {
        $cellTypes = self::extractKnownArrayElementTypes($rowType);
        if ($cellTypes === null) {
            return null;
        }

        if ($cellTypes === []) {
            return ['inputCount' => -1, 'returnType' => null];
        }

        return [
            'inputCount' => count($cellTypes) - 1,
            'returnType' => $cellTypes[array_key_last($cellTypes)],
        ];
    }

    private static function requiredParameterCount(FunctionLikeMetadata $method): int
    {
        $required = 0;
        foreach ($method->parameters as $parameter) {
            if (
                !$parameter->flags->contains(MetadataFlags::VARIADIC)
                && !$parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
            ) {
                ++$required;
            }
        }

        return $required;
    }

    /**
     * @param non-empty-list<string> $targets
     *
     * @return array{kind: 'throws'|'unknown'}|array{kind: 'payloads', items: non-empty-list<array{type: Type, requireContainment: bool, label?: string}>}
     */
    private function resolveNestedConsecutiveCallsOutcome(
        NodeAnalysisContext $context,
        SourceFile $source,
        ?Node $stubNode,
        array $targets,
        FunctionLikeMetadata $declaringMethod,
        int $depth,
    ): array {
        $entries = $this->extractConsecutiveCallsEntries($context, $source, $stubNode);
        if ($entries === null) {
            return ['kind' => 'unknown'];
        }

        if ($entries === []) {
            return ['kind' => 'throws'];
        }

        $items = [];
        $allThrow = true;
        foreach ($entries as $entry) {
            $entryType = $entry['type'];
            if (self::containsStubObject($context, $entryType)) {
                if (!self::isStubObject($context, $entryType)) {
                    $allThrow = false;
                    continue;
                }
                $nested = $this->resolveConsecutiveStubOutcome(
                    $context,
                    $entryType,
                    $entry['source'],
                    $entry['node'],
                    $targets,
                    $declaringMethod,
                    $depth,
                );
                if ($nested['kind'] === 'throws') {
                    continue;
                }
                $allThrow = false;
                if ($nested['kind'] === 'payloads') {
                    foreach ($nested['items'] as $item) {
                        $items[] = $item;
                    }
                }
                continue;
            }

            $allThrow = false;
            $items[] = [
                'type' => $entryType,
                'requireContainment' => false,
            ];
        }

        if ($allThrow) {
            return ['kind' => 'throws'];
        }

        if ($items === []) {
            return ['kind' => 'unknown'];
        }

        return [
            'kind' => 'payloads',
            'items' => $items,
        ];
    }

    /**
     * @return null|list<array{type: Type, source: SourceFile, node: ?Node}>
     */
    private function extractConsecutiveCallsEntries(
        NodeAnalysisContext $context,
        SourceFile $source,
        ?Node $stubNode,
    ): ?array {
        $node = $this->unwrap($source, $stubNode);
        if ($node === null) {
            return null;
        }

        if ($node->kind === NodeKind::Instantiation) {
            $stackNode = null;
            foreach ($source->getChildren($node) as $child) {
                if ($child->kind === NodeKind::ArgumentList) {
                    $stackNode = $this->firstOrNamedArgumentValue($source, $child, ['stack']);
                    break;
                }
            }
            if ($stackNode === null) {
                return null;
            }

            $resolvedStack = $this->resolveLocalExpression($context, $source, $stackNode);
            $stackSource = $resolvedStack !== null ? $resolvedStack[0] : $source;
            $stackExpr = $resolvedStack !== null ? $resolvedStack[1] : $stackNode;
            $stackUnwrapped = $this->unwrap($stackSource, $stackExpr);
            if ($stackUnwrapped !== null && ($stackUnwrapped->kind === NodeKind::Array || $stackUnwrapped->kind === NodeKind::LegacyArray)) {
                $elementNodes = $this->extractLiteralArrayElementNodes($stackSource, $stackUnwrapped);
                if ($elementNodes !== null) {
                    $entries = [];
                    foreach ($elementNodes as $elementNode) {
                        $elementType = $this->expressionType($context, $stackSource, $elementNode);
                        if ($elementType === null) {
                            return null;
                        }
                        $entries[] = [
                            'type' => $elementType,
                            'source' => $stackSource,
                            'node' => $elementNode,
                        ];
                    }

                    return $entries;
                }
            }

            if ($stackUnwrapped !== null && $stackUnwrapped->kind === NodeKind::DirectVariable && $this->isReferenceAliasedLocal($context, $stackSource, $stackUnwrapped)) {
                return null;
            }

            $stackType = $this->expressionType($context, $stackSource, $stackExpr);
            if ($stackType === null) {
                return null;
            }

            $elementTypes = self::extractKnownArrayElementTypes($stackType);
            if ($elementTypes === null) {
                return null;
            }

            $entries = [];
            foreach ($elementTypes as $elementType) {
                $entries[] = [
                    'type' => $elementType,
                    'source' => $stackSource,
                    'node' => null,
                ];
            }

            return $entries;
        }

        if ($node->kind === NodeKind::MethodCall || $node->kind === NodeKind::StaticMethodCall) {
            $call = CallExpression::fromNode($source, $node);
            if (strtolower($call->getName($source) ?? '') !== 'onconsecutivecalls') {
                return null;
            }
            if (!$this->isPhpUnitTestCaseHelperCall($context, $source, $node, 'onconsecutivecalls')) {
                return null;
            }

            $entries = [];
            foreach ($call->arguments as $arg) {
                $argType = $this->expressionType($context, $source, $arg->value);
                if ($argType === null) {
                    return null;
                }
                foreach ($this->extractCallCandidates($context, $argType, $source, $arg->value, $arg->unpacked) as $candidate) {
                    $entries[] = $candidate;
                }
            }

            return $entries;
        }

        return null;
    }

    /**
     * @return null|list<Type>
     */
    private static function extractKnownArrayElementTypes(Type $type): ?array
    {
        if (count($type->atomicTypes) !== 1) {
            return null;
        }

        $atomic = $type->atomicTypes[0];
        $types = [];
        if ($atomic instanceof ListType) {
            if ($atomic->knownElements === null) {
                return $atomic->knownCount === 0 ? [] : null;
            }
            foreach ($atomic->knownElements as $element) {
                if ($element->optional) {
                    return null;
                }
                $types[] = $element->type;
            }

            return $types;
        }

        if ($atomic instanceof KeyedArrayType) {
            if ($atomic->knownItems === null) {
                return $atomic->keyType === null && $atomic->valueType === null ? [] : null;
            }
            foreach ($atomic->knownItems as $item) {
                if ($item->optional) {
                    return null;
                }
                $types[] = $item->type;
            }

            return $types;
        }

        return null;
    }

    /**
     * @return null|array{0: SourceFile, 1: Node}
     */
    private function resolveLocalExpression(NodeAnalysisContext $context, SourceFile $source, Node $node): ?array
    {
        $currentSource = $source;
        $currentNode = $this->unwrap($currentSource, $node);
        if ($currentNode === null) {
            return null;
        }

        $visited = [];
        for ($step = 0; $step < self::MAX_LOCAL_RESOLUTION_DEPTH; ++$step) {
            if ($currentNode->kind === NodeKind::Assignment) {
                $parts = $currentSource->getChildren($currentNode);
                $op = $parts[1] ?? null;
                $rhs = $parts[2] ?? null;
                if ($op === null || $rhs === null || trim($currentSource->getText($op)) !== '=') {
                    return null;
                }
                $currentNode = $this->unwrap($currentSource, $rhs);
                if ($currentNode === null) {
                    return null;
                }
                continue;
            }

            if ($currentNode->kind !== NodeKind::DirectVariable) {
                return [$currentSource, $currentNode];
            }

            $varName = $currentSource->getText($currentNode);
            if ($varName === '' || $varName === '$this' || $varName[0] !== '$' || isset($visited[$varName])) {
                return null;
            }
            $visited[$varName] = true;

            $fullSource = $context->analysis->getSourceFile();
            $useNode = $this->findMatchingNode($fullSource, $currentNode);
            if ($useNode === null) {
                return null;
            }

            $rhsNode = $this->findUnambiguousLocalAssignment($context, $fullSource, $useNode, $varName);
            if ($rhsNode === null) {
                return null;
            }

            $currentSource = $fullSource;
            $currentNode = $this->unwrap($fullSource, $rhsNode);
            if ($currentNode === null) {
                return null;
            }
        }

        return $currentNode->kind === NodeKind::DirectVariable ? null : [$currentSource, $currentNode];
    }

    private function findMatchingNode(SourceFile $fullSource, Node $target): ?Node
    {
        foreach ($fullSource->getNodes($target->kind) as $candidate) {
            if ($candidate->span->start === $target->span->start && $candidate->span->end === $target->span->end) {
                return $candidate;
            }
        }

        return null;
    }

    private function isReferenceAliasedLocal(NodeAnalysisContext $context, SourceFile $source, Node $varNode): bool
    {
        $varName = $source->getText($varNode);
        if ($varName === '' || $varName === '$this' || $varName[0] !== '$') {
            return false;
        }

        $fullSource = $context->analysis->getSourceFile();
        $useNode = $this->findMatchingNode($fullSource, $varNode);
        if ($useNode === null) {
            return false;
        }

        $scope = $this->enclosingExecutableScope($fullSource, $useNode);
        if ($scope === null || $scope->kind === NodeKind::ArrowFunction) {
            return false;
        }

        $facts = $this->indexScopeLocals($context, $fullSource, $scope);

        return $facts['dynamicWrite'] || isset($facts['ambiguous'][$varName]);
    }

    private function findUnambiguousLocalAssignment(NodeAnalysisContext $context, SourceFile $source, Node $useNode, string $varName): ?Node
    {
        $scope = $this->enclosingExecutableScope($source, $useNode);
        if ($scope === null || $scope->kind === NodeKind::ArrowFunction) {
            return null;
        }

        $facts = $this->indexScopeLocals($context, $source, $scope);
        if ($facts['dynamicWrite'] || isset($facts['ambiguous'][$varName])) {
            return null;
        }

        $writes = $facts['writes'][$varName] ?? [];
        if (count($writes) !== 1) {
            return null;
        }

        $write = $writes[0];
        if ($write['containerId'] === null || $write['assign']->span->end > $useNode->span->start) {
            return null;
        }

        $callArgOffsets = $facts['callArgOffsets'][$varName] ?? [];
        foreach ($callArgOffsets as $argOffset) {
            if ($write['assign']->span->end <= $argOffset && $argOffset < $useNode->span->start) {
                return null;
            }
        }

        foreach ($source->getAncestors($useNode) as $ancestor) {
            if ($ancestor->id === $write['containerId']) {
                return $write['rhs'];
            }
            if (
                $callArgOffsets !== []
                && (
                    $ancestor->kind === NodeKind::For
                    || $ancestor->kind === NodeKind::Foreach
                    || $ancestor->kind === NodeKind::While
                    || $ancestor->kind === NodeKind::DoWhile
                )
                && $ancestor->span->start > $write['assign']->span->end
            ) {
                foreach ($callArgOffsets as $argOffset) {
                    if ($ancestor->span->start <= $argOffset && $argOffset < $ancestor->span->end) {
                        return null;
                    }
                }
            }
        }

        return null;
    }

    private function enclosingExecutableScope(SourceFile $source, Node $node): ?Node
    {
        foreach ($source->getAncestors($node) as $ancestor) {
            if (
                $ancestor->kind === NodeKind::Method
                || $ancestor->kind === NodeKind::Function
                || $ancestor->kind === NodeKind::Closure
                || $ancestor->kind === NodeKind::ArrowFunction
                || $ancestor->kind === NodeKind::Program
            ) {
                return $ancestor;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     dynamicWrite: bool,
     *     ambiguous: array<string, true>,
     *     writes: array<string, list<array{assign: Node, rhs: Node, containerId: ?int}>>,
     *     callArgOffsets: array<string, list<int>>,
     * }
     */
    private function indexScopeLocals(NodeAnalysisContext $context, SourceFile $source, Node $scope): array
    {
        if ($this->lastScopeSource?->get() !== $source) {
            $this->lastScopeSource = WeakReference::create($source);
            $this->lastScopeLocalIndex = [];
        }
        if (isset($this->lastScopeLocalIndex[$scope->id])) {
            return $this->lastScopeLocalIndex[$scope->id];
        }

        $dynamicWrite = false;
        $ambiguous = [];
        $writes = [];
        $callArgOffsets = [];

        $stack = $source->getChildren($scope);
        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node->kind === NodeKind::FunctionLikeParameter) {
                foreach ($source->getDescendants($node, NodeKind::DirectVariable) as $paramVar) {
                    $ambiguous[$source->getText($paramVar)] = true;
                }
                continue;
            }

            if ($node->kind === NodeKind::ClosureUseClauseVariable) {
                $text = preg_replace('/\s+/', '', $source->getText($node));
                $ambiguous[ltrim($text, '&')] = true;
                continue;
            }

            if (
                $node->kind === NodeKind::Closure
                || $node->kind === NodeKind::ArrowFunction
                || $node->kind === NodeKind::Function
                || $node->kind === NodeKind::Method
                || $node->kind === NodeKind::Class_
                || $node->kind === NodeKind::AnonymousClass
                || $node->kind === NodeKind::Interface
                || $node->kind === NodeKind::Trait
                || $node->kind === NodeKind::Enum
            ) {
                if ($node->kind === NodeKind::AnonymousClass) {
                    foreach ($source->getChildren($node) as $child) {
                        if ($child->kind === NodeKind::ArgumentList || $child->kind === NodeKind::PartialArgumentList) {
                            $stack[] = $child;
                        }
                    }
                }
                foreach ($source->getDescendants($node, NodeKind::ClosureUseClauseVariable) as $useVar) {
                    $text = preg_replace('/\s+/', '', $source->getText($useVar));
                    if (str_starts_with($text, '&')) {
                        $ambiguous[ltrim($text, '&')] = true;
                    }
                }
                continue;
            }

            if (
                $node->kind === NodeKind::EvalConstruct
                || $node->kind === NodeKind::IncludeConstruct
                || $node->kind === NodeKind::IncludeOnceConstruct
                || $node->kind === NodeKind::RequireConstruct
                || $node->kind === NodeKind::RequireOnceConstruct
                || $node->kind === NodeKind::Goto
            ) {
                $dynamicWrite = true;
            } elseif ($node->kind === NodeKind::FunctionCall && $this->isExtractCall($source, $node)) {
                $dynamicWrite = true;
            }

            if (
                ($node->kind === NodeKind::ArgumentList || $node->kind === NodeKind::PartialArgumentList)
                && !$this->isKnownByValueCallOrInstantiation($context, $source, $source->getParent($node))
            ) {
                $this->recordCallArgVariables($source, $node, $callArgOffsets);
            }

            if (
                ($node->kind === NodeKind::ValueArrayElement || $node->kind === NodeKind::KeyValueArrayElement)
                && preg_match('/(?:^|=>)\s*&\s*\$/', trim($source->getText($node))) === 1
            ) {
                foreach ($source->getDescendants($node, NodeKind::DirectVariable) as $refVar) {
                    $ambiguous[$source->getText($refVar)] = true;
                }
            }

            if ($node->kind === NodeKind::UnaryPrefix || $node->kind === NodeKind::UnaryPostfix) {
                $parts = $source->getChildren($node);
                $opNode = $node->kind === NodeKind::UnaryPrefix ? ($parts[0] ?? null) : ($parts[1] ?? null);
                $operandNode = $node->kind === NodeKind::UnaryPrefix ? ($parts[1] ?? null) : ($parts[0] ?? null);
                $opText = $opNode !== null ? trim($source->getText($opNode)) : '';
                if (($opText === '++' || $opText === '--') && $operandNode !== null) {
                    $target = $this->unwrap($source, $operandNode);
                    if ($target !== null && $target->kind === NodeKind::DirectVariable) {
                        $ambiguous[$source->getText($target)] = true;
                    } elseif ($target !== null && ($target->kind === NodeKind::ArrayAccess || $target->kind === NodeKind::ArrayAppend)) {
                        $root = $this->rootVariableOfArrayTarget($source, $target);
                        if ($root !== null) {
                            $ambiguous[$root] = true;
                        }
                    }
                }
            }

            if (
                $node->kind === NodeKind::Global
                || $node->kind === NodeKind::Static
                || $node->kind === NodeKind::Unset
                || $node->kind === NodeKind::ForeachTarget
                || $node->kind === NodeKind::ForeachValueTarget
                || $node->kind === NodeKind::ForeachKeyValueTarget
            ) {
                foreach ($source->getDescendants($node, NodeKind::DirectVariable) as $var) {
                    $ambiguous[$source->getText($var)] = true;
                }
                continue;
            }

            if ($node->kind === NodeKind::TryCatchClause) {
                foreach ($source->getChildren($node) as $child) {
                    if ($child->kind === NodeKind::DirectVariable || $child->kind === NodeKind::Variable) {
                        $catchVar = $this->unwrap($source, $child);
                        if ($catchVar !== null && $catchVar->kind === NodeKind::DirectVariable) {
                            $ambiguous[$source->getText($catchVar)] = true;
                        }
                    } else {
                        $stack[] = $child;
                    }
                }
                continue;
            }

            if ($node->kind === NodeKind::Assignment) {
                $parts = $source->getChildren($node);
                $lhs = $parts[0] ?? null;
                $op = $parts[1] ?? null;
                $rhs = $parts[2] ?? null;
                $opText = $op !== null ? trim($source->getText($op)) : '';
                $rhsHasReference = $rhs !== null && preg_match('/(?:^|[(\[,]|=>)\s*&\s*\$/', $source->getText($rhs)) === 1;

                if ($lhs !== null) {
                    if (
                        $source->getFirstDescendant($lhs, NodeKind::IndirectVariable) !== null
                        || $source->getFirstDescendant($lhs, NodeKind::NestedVariable) !== null
                    ) {
                        $dynamicWrite = true;
                    }

                    $unwrappedLhs = $this->unwrap($source, $lhs);
                    if ($unwrappedLhs !== null && $unwrappedLhs->kind === NodeKind::DirectVariable && $rhs !== null) {
                        $name = $source->getText($unwrappedLhs);
                        if ($opText !== '=' || $rhsHasReference) {
                            $ambiguous[$name] = true;
                        } else {
                            $writes[$name][] = [
                                'assign' => $node,
                                'rhs' => $rhs,
                                'containerId' => $this->dominatingStatementContainerId($source, $node),
                            ];
                        }
                    } elseif ($unwrappedLhs !== null && ($unwrappedLhs->kind === NodeKind::Array || $unwrappedLhs->kind === NodeKind::List)) {
                        foreach ($source->getDescendants($unwrappedLhs, NodeKind::DirectVariable) as $var) {
                            $ambiguous[$source->getText($var)] = true;
                        }
                    } elseif ($unwrappedLhs !== null && ($unwrappedLhs->kind === NodeKind::ArrayAccess || $unwrappedLhs->kind === NodeKind::ArrayAppend)) {
                        $root = $this->rootVariableOfArrayTarget($source, $unwrappedLhs);
                        if ($root !== null) {
                            $ambiguous[$root] = true;
                        }
                    }
                }

                if ($rhs !== null && ($opText === '=&' || $rhsHasReference)) {
                    foreach ($source->getDescendants($rhs, NodeKind::DirectVariable) as $refVar) {
                        $ambiguous[$source->getText($refVar)] = true;
                    }
                }
            }

            foreach ($source->getChildren($node) as $child) {
                $stack[] = $child;
            }
        }

        $facts = [
            'dynamicWrite' => $dynamicWrite,
            'ambiguous' => $ambiguous,
            'writes' => $writes,
            'callArgOffsets' => $callArgOffsets,
        ];
        $this->lastScopeLocalIndex[$scope->id] = $facts;

        return $facts;
    }

    private function isExtractCall(SourceFile $source, Node $functionCall): bool
    {
        $callee = $source->getChildren($functionCall)[0] ?? null;
        while (
            $callee !== null
            && (
                $callee->kind === NodeKind::Expression
                || $callee->kind === NodeKind::ConstantAccess
                || $callee->kind === NodeKind::Identifier
            )
        ) {
            $callee = $source->getChildren($callee)[0] ?? null;
        }
        if (
            $callee === null
            || (
                $callee->kind !== NodeKind::LocalIdentifier
                && $callee->kind !== NodeKind::QualifiedIdentifier
                && $callee->kind !== NodeKind::FullyQualifiedIdentifier
            )
        ) {
            return false;
        }

        $raw = strtolower(ltrim(trim($source->getText($callee)), '\\'));
        if ($raw === 'extract') {
            return true;
        }

        $resolved = $source->getResolvedName($callee)?->name;

        return $resolved !== null && strtolower(ltrim($resolved, '\\')) === 'extract';
    }

    private function isKnownByValueCallOrInstantiation(NodeAnalysisContext $context, SourceFile $source, ?Node $parent): bool
    {
        if ($parent === null) {
            return false;
        }

        if ($parent->kind === NodeKind::Instantiation) {
            $classNode = null;
            foreach ($source->getChildren($parent) as $child) {
                if (
                    $child->kind === NodeKind::Keyword
                    || $child->kind === NodeKind::ArgumentList
                    || $child->kind === NodeKind::PartialArgumentList
                ) {
                    continue;
                }
                $classNode = $this->unwrap($source, $child);
                break;
            }
            while ($classNode !== null && $classNode->kind === NodeKind::Identifier) {
                $classNode = $source->getChildren($classNode)[0] ?? null;
            }
            if (
                $classNode === null
                || (
                    $classNode->kind !== NodeKind::LocalIdentifier
                    && $classNode->kind !== NodeKind::QualifiedIdentifier
                    && $classNode->kind !== NodeKind::FullyQualifiedIdentifier
                )
            ) {
                return false;
            }

            $resolved = $source->getResolvedName($classNode)?->name ?? $source->getText($classNode);

            return isset(self::BY_VALUE_STUB_CLASSES[strtolower(ltrim($resolved, '\\'))]);
        }

        if (
            $parent->kind === NodeKind::MethodCall
            || $parent->kind === NodeKind::NullSafeMethodCall
            || $parent->kind === NodeKind::StaticMethodCall
        ) {
            $methodName = strtolower($this->calledMethodName($source, $parent) ?? '');
            if ($methodName === 'willreturn' || $methodName === 'willreturnonconsecutivecalls') {
                $rawReceiver = $source->getChildren($parent)[0] ?? null;
                if ($rawReceiver === null) {
                    return false;
                }
                $receiverType = $this->expressionType($context, $source, $rawReceiver);
                if ($receiverType === null || $receiverType->atomicTypes === []) {
                    return false;
                }
                foreach ($receiverType->atomicTypes as $atomic) {
                    if (!$atomic instanceof NamedObjectType || !isset(self::MOCK_CLASSES[strtolower($atomic->name)])) {
                        return false;
                    }
                }

                return true;
            }

            if (isset(self::BY_VALUE_TESTCASE_HELPERS[$methodName])) {
                $receiver = $this->unwrap($source, $source->getChildren($parent)[0] ?? null);
                if ($receiver === null) {
                    return false;
                }
                $text = strtolower(trim($source->getText($receiver)));
                if ($text === '$this' || $text === 'self' || $text === 'static' || $text === 'parent') {
                    return true;
                }
                $resolved = $source->getResolvedName($receiver)?->name;

                return $resolved !== null && strtolower(ltrim($resolved, '\\')) === 'phpunit\\framework\\testcase';
            }
        }

        return false;
    }

    /**
     * @param array<string, list<int>> $callArgOffsets
     */
    private function recordCallArgVariables(SourceFile $source, Node $argumentList, array &$callArgOffsets): void
    {
        foreach ($source->getChildren($argumentList) as $argument) {
            $variant = $source->getChildren($argument)[0] ?? null;
            if (
                $variant === null
                || ($variant->kind !== NodeKind::PositionalArgument && $variant->kind !== NodeKind::NamedArgument)
            ) {
                continue;
            }
            if (str_starts_with($source->getText($variant), '...')) {
                continue;
            }

            $parts = $source->getChildren($variant);
            $value = $parts[$variant->kind === NodeKind::NamedArgument ? 1 : 0] ?? null;
            $unwrapped = $this->unwrap($source, $value);
            if ($unwrapped === null) {
                continue;
            }

            if ($unwrapped->kind === NodeKind::DirectVariable) {
                $callArgOffsets[$source->getText($unwrapped)][] = $unwrapped->span->start;
            } elseif ($unwrapped->kind === NodeKind::ArrayAccess) {
                $root = $this->rootVariableOfArrayTarget($source, $unwrapped);
                if ($root !== null) {
                    $callArgOffsets[$root][] = $unwrapped->span->start;
                }
            }
        }
    }

    private function dominatingStatementContainerId(SourceFile $source, Node $assign): ?int
    {
        $parent = $source->getParent($assign);
        while ($parent !== null && ($parent->kind === NodeKind::Expression || $parent->kind === NodeKind::Parenthesized)) {
            $parent = $source->getParent($parent);
        }

        if ($parent === null || $parent->kind !== NodeKind::ExpressionStatement) {
            return null;
        }

        $container = $source->getParent($parent);
        while ($container !== null && $container->kind === NodeKind::Statement) {
            $container = $source->getParent($container);
        }

        if (
            $container !== null
            && (
                $container->kind === NodeKind::Block
                || $container->kind === NodeKind::MethodBody
                || $container->kind === NodeKind::Program
                || $container->kind === NodeKind::NamespaceBody
                || $container->kind === NodeKind::NamespaceImplicitBody
                || $container->kind === NodeKind::IfColonDelimitedBody
                || $container->kind === NodeKind::IfColonDelimitedBodyElseClause
                || $container->kind === NodeKind::IfColonDelimitedBodyElseIfClause
            )
        ) {
            return $container->id;
        }

        return null;
    }

    private function rootVariableOfArrayTarget(SourceFile $source, Node $node): ?string
    {
        $current = $node;
        while ($current !== null && ($current->kind === NodeKind::ArrayAccess || $current->kind === NodeKind::ArrayAppend)) {
            $current = $this->unwrap($source, $source->getChildren($current)[0] ?? null);
        }

        return $current !== null && $current->kind === NodeKind::DirectVariable
            ? $source->getText($current)
            : null;
    }

    private function resolveLiteralInt(NodeAnalysisContext $context, SourceFile $source, Node $node): ?int
    {
        $type = $this->expressionType($context, $source, $node);
        $literal = $type?->getLiteralInt();
        if ($literal !== null) {
            return $literal;
        }

        $unwrapped = $this->unwrap($source, $node);
        if ($unwrapped === null) {
            return null;
        }

        if ($type !== null && $unwrapped->kind === NodeKind::DirectVariable) {
            return null;
        }

        $resolved = $this->resolveLocalExpression($context, $source, $unwrapped);
        $exprSource = $resolved !== null ? $resolved[0] : $source;
        $exprNode = $resolved !== null ? $resolved[1] : $unwrapped;

        $resolvedType = $this->expressionType($context, $exprSource, $exprNode);
        $resolvedLiteral = $resolvedType?->getLiteralInt();
        if ($resolvedLiteral !== null) {
            return $resolvedLiteral;
        }

        return $this->literalIntFromAst($exprSource, $exprNode);
    }

    private function literalIntFromAst(SourceFile $source, ?Node $node): ?int
    {
        $node = $this->unwrap($source, $node);
        if ($node === null) {
            return null;
        }

        if ($node->kind === NodeKind::LiteralInteger) {
            $raw = str_replace('_', '', trim($source->getText($node)));
            if (preg_match('/^[0-9]+$/', $raw) === 1) {
                return (int) $raw;
            }

            return null;
        }

        if ($node->kind === NodeKind::UnaryPrefix) {
            $parts = $source->getChildren($node);
            $op = isset($parts[0]) ? trim($source->getText($parts[0])) : '';
            $operand = $parts[1] ?? null;
            if (($op === '-' || $op === '+') && $operand !== null) {
                $inner = $this->literalIntFromAst($source, $operand);
                if ($inner !== null) {
                    return $op === '-' ? -$inner : $inner;
                }
            }
        }

        return null;
    }

    /**
     * @param non-empty-list<string> $targets
     */
    private static function buildMockTargetType(array $targets): Type
    {
        if (count($targets) === 1) {
            return Type::namedObject($targets[0]);
        }

        $intersections = [];
        for ($i = 1, $total = count($targets); $i < $total; ++$i) {
            $intersections[] = new NamedObjectType($targets[$i], [], null, false, false, null, false);
        }

        return Type::fromAtomic(new NamedObjectType($targets[0], [], null, false, false, $intersections, false));
    }

    /**
     * @param non-empty-list<string> $targets
     */
    private function resolveRelativeObjectTypes(
        NodeAnalysisContext $context,
        Type $type,
        array $targets,
        ?string $declaringClass,
    ): Type {
        $changed = false;
        /** @var list<AtomicType> $resolvedAtomics */
        $resolvedAtomics = [];
        foreach ($type->atomicTypes as $atomic) {
            if (!$atomic instanceof NamedObjectType) {
                $resolvedAtomics[] = $atomic;
                continue;
            }

            $lower = strtolower($atomic->name);
            if ($lower === 'static' || $atomic->static) {
                $changed = true;
                $resolvedAtomics[] = self::buildMockTargetType($targets)->atomicTypes[0];
                continue;
            }

            if ($lower === 'self' && $declaringClass !== null) {
                $changed = true;
                $resolvedAtomics[] = new NamedObjectType($declaringClass, [], null, false, false, null, false);
                continue;
            }

            if ($lower === 'parent' && $declaringClass !== null) {
                $parentClass = $context->codebase->getClassLike($declaringClass)?->directParentClass;
                if ($parentClass !== null) {
                    $changed = true;
                    $resolvedAtomics[] = new NamedObjectType($parentClass, [], null, false, false, null, false);
                    continue;
                }
            }

            $resolvedAtomics[] = $atomic;
        }

        if (!$changed) {
            return $type;
        }

        $first = array_shift($resolvedAtomics);

        return Type::fromAtomics($first, ...$resolvedAtomics);
    }

    /**
     * @param non-empty-list<string> $namedParameters
     */
    private function extractStubArgumentNode(
        NodeAnalysisContext $context,
        SourceFile $source,
        ?Node $stubNode,
        array $namedParameters,
        string $helperMethodName,
    ): ?Node {
        $node = $this->unwrap($source, $stubNode);
        if ($node === null) {
            return null;
        }

        if ($node->kind === NodeKind::Instantiation) {
            foreach ($source->getChildren($node) as $child) {
                if ($child->kind === NodeKind::ArgumentList) {
                    return $this->firstOrNamedArgumentValue($source, $child, $namedParameters);
                }
            }

            return null;
        }

        if ($node->kind === NodeKind::MethodCall || $node->kind === NodeKind::StaticMethodCall) {
            $call = CallExpression::fromNode($source, $node);
            if (strtolower($call->getName($source) ?? '') !== $helperMethodName) {
                return null;
            }
            if (!$this->isPhpUnitTestCaseHelperCall($context, $source, $node, $helperMethodName)) {
                return null;
            }

            foreach ($call->arguments as $arg) {
                if ($arg->name !== null && in_array($arg->name, $namedParameters, true) && !$arg->unpacked) {
                    return $arg->value;
                }
            }

            $first = $call->arguments[0] ?? null;
            if ($first !== null && $first->name === null && !$first->unpacked) {
                return $first->value;
            }
        }

        return null;
    }

    private function isPhpUnitTestCaseHelperCall(
        NodeAnalysisContext $context,
        SourceFile $source,
        Node $callNode,
        string $helperMethodName,
    ): bool {
        $rawTarget = $source->getChildren($callNode)[0] ?? null;
        if ($rawTarget === null) {
            return false;
        }

        $classNames = [];
        if ($callNode->kind === NodeKind::MethodCall) {
            $receiverType = $this->expressionType($context, $source, $rawTarget);
            if ($receiverType === null || $receiverType->atomicTypes === []) {
                return false;
            }
            foreach ($receiverType->atomicTypes as $atomic) {
                if (!$atomic instanceof NamedObjectType) {
                    return false;
                }
                $classNames[] = $atomic->name;
            }
        } else {
            $target = $this->unwrap($source, $rawTarget);
            if ($target === null) {
                return false;
            }
            $text = strtolower(trim($source->getText($target)));
            if ($text === 'self' || $text === 'static' || $text === 'parent') {
                $enclosing = $this->enclosingClassName($source, $callNode);
                if ($enclosing === null) {
                    return false;
                }
                if ($text === 'parent') {
                    $meta = $context->codebase->getClass($enclosing);
                    if ($meta === null || $meta->directParentClass === null) {
                        return false;
                    }
                    $classNames[] = $meta->directParentClass;
                } else {
                    $classNames[] = $enclosing;
                }
            } else {
                $resolved = $source->getResolvedName($target)?->name ?? $source->getText($target);
                $classNames[] = ltrim($resolved, '\\');
            }
        }

        foreach ($classNames as $className) {
            $declaring = $context->codebase->getDeclaringMethod($className, $helperMethodName);
            if ($declaring === null || strtolower(ltrim($declaring->identifier->class ?? '', '\\')) !== 'phpunit\\framework\\testcase') {
                return false;
            }
        }

        return true;
    }

    private function enclosingClassName(SourceFile $source, Node $node): ?string
    {
        $parent = $source->getParent($node);
        while ($parent !== null) {
            if ($parent->kind === NodeKind::Class_) {
                foreach ($source->getChildren($parent) as $child) {
                    if ($child->kind === NodeKind::LocalIdentifier) {
                        return $source->getResolvedName($child)?->name ?? $source->getText($child);
                    }
                }
            }
            $parent = $source->getParent($parent);
        }

        return null;
    }

    /**
     * @param non-empty-list<string> $namedParameters
     */
    private function firstOrNamedArgumentValue(SourceFile $source, Node $argumentList, array $namedParameters): ?Node
    {
        $positionalFirst = null;
        $index = 0;

        foreach ($source->getChildren($argumentList) as $argument) {
            $variant = $source->getChildren($argument)[0] ?? null;
            if ($variant === null) {
                continue;
            }

            $parts = $source->getChildren($variant);
            $named = $variant->kind === NodeKind::NamedArgument;
            $value = $parts[$named ? 1 : 0] ?? null;
            if ($value === null) {
                continue;
            }

            $unpacked = str_starts_with($source->getText($variant), '...');
            if ($named) {
                if (!$unpacked && in_array($source->getText($parts[0]), $namedParameters, true)) {
                    return $value;
                }
            } elseif ($index === 0 && !$unpacked) {
                $positionalFirst = $value;
            }

            ++$index;
        }

        return $positionalFirst;
    }

    private function expressionType(NodeAnalysisContext $context, SourceFile $source, Node $node): ?Type
    {
        $type = $context->analysis->getExpressionType($node);
        if ($type instanceof Type) {
            return $type;
        }

        $unwrapped = $this->unwrap($source, $node);
        if ($unwrapped !== null && $unwrapped !== $node) {
            return $context->analysis->getExpressionType($unwrapped);
        }

        return null;
    }

    private function resolveCallbackReturnType(NodeAnalysisContext $context, Type $callbackType): ?Type
    {
        $returnTypes = [];
        foreach ($callbackType->atomicTypes as $atomic) {
            $resolved = $this->resolveAtomicCallbackReturnType($context, $atomic);
            if ($resolved === null) {
                return null;
            }
            $returnTypes[] = self::isVoid($resolved) ? Type::null() : $resolved;
        }

        $combined = $returnTypes[0];
        for ($i = 1, $total = count($returnTypes); $i < $total; ++$i) {
            $combined = Type::union($combined, $returnTypes[$i]);
        }

        return $combined;
    }

    private function resolveAtomicCallbackReturnType(NodeAnalysisContext $context, object $atomic): ?Type
    {
        if ($atomic instanceof CallableType) {
            $returnType = $atomic->signature?->returnType;
            if ($returnType instanceof Type && !self::containsMixedOrTemplate($returnType)) {
                return $returnType;
            }

            $identifier = $atomic->alias ?? $atomic->signature?->source;
            if ($identifier !== null) {
                return self::functionLikeReturnType($context->codebase->getFunctionLike($identifier));
            }

            return null;
        }

        if (
            $atomic instanceof ScalarType
            && $atomic->refinement instanceof StringType
            && $atomic->refinement->literalKind === StringLiteralKind::Value
            && $atomic->refinement->literalValue !== null
        ) {
            $functionName = ltrim($atomic->refinement->literalValue, '\\');
            if ($functionName === '' || str_contains($functionName, '::')) {
                return null;
            }

            return self::functionLikeReturnType($context->codebase->getFunction($functionName));
        }

        if ($atomic instanceof ListType || $atomic instanceof KeyedArrayType) {
            return $this->resolveArrayCallableReturnType($context, $atomic);
        }

        return null;
    }

    private static function functionLikeReturnType(?FunctionLikeMetadata $fnMeta): ?Type
    {
        if ($fnMeta === null) {
            return null;
        }

        $returnType = $fnMeta->returnType?->type;
        if ($returnType instanceof Type && !self::containsMixedOrTemplate($returnType)) {
            return $returnType;
        }

        $declared = $fnMeta->declaredReturnType?->type;
        if ($declared instanceof Type && !self::containsMixedOrTemplate($declared)) {
            return $declared;
        }

        return null;
    }

    private function resolveArrayCallableReturnType(NodeAnalysisContext $context, ListType|KeyedArrayType $atomic): ?Type
    {
        $elements = [];
        if ($atomic instanceof ListType) {
            if ($atomic->knownElements === null || count($atomic->knownElements) !== 2) {
                return null;
            }
            foreach ($atomic->knownElements as $element) {
                if ($element->optional) {
                    return null;
                }
                $elements[] = $element->type;
            }
        } else {
            if ($atomic->knownItems === null || count($atomic->knownItems) !== 2) {
                return null;
            }
            foreach ($atomic->knownItems as $item) {
                if ($item->optional) {
                    return null;
                }
                $elements[] = $item->type;
            }
        }

        if (count($elements) !== 2) {
            return null;
        }

        $methodName = $elements[1]->getLiteralString();
        if ($methodName === null || $methodName === '') {
            return null;
        }

        $targetType = $elements[0];
        $className = null;
        if (count($targetType->atomicTypes) === 1 && $targetType->atomicTypes[0] instanceof NamedObjectType) {
            $className = $targetType->atomicTypes[0]->name;
        } else {
            $className = $targetType->getLiteralClassString() ?? $targetType->getLiteralString();
        }

        if ($className === null || $className === '') {
            return null;
        }

        $classMeta = $context->codebase->getClassLike($className);
        if (!$classMeta instanceof ClassLikeMetadata || $classMeta->hasIncompleteHierarchy()) {
            return null;
        }

        $methodMeta = $context->codebase->getDeclaringMethod($className, $methodName);

        return self::functionLikeReturnType($methodMeta);
    }

    /**
     * @return list<Node>
     */
    private function extractUnpackedLiteralArrayNodes(SourceFile $source, Node $node): array
    {
        $arrayNode = $this->unwrap($source, $node);
        if ($arrayNode === null || ($arrayNode->kind !== NodeKind::Array && $arrayNode->kind !== NodeKind::LegacyArray)) {
            return [];
        }

        return $this->extractLiteralArrayElementNodes($source, $arrayNode) ?? [];
    }

    /**
     * @return null|list<Node>
     */
    private function extractLiteralArrayElementNodes(SourceFile $source, Node $arrayNode): ?array
    {
        $nodes = [];
        foreach ($source->getChildren($arrayNode) as $element) {
            if ($element->kind !== NodeKind::ArrayElement) {
                continue;
            }
            $variant = $source->getChildren($element)[0] ?? null;
            if ($variant === null) {
                return null;
            }
            if ($variant->kind === NodeKind::ValueArrayElement) {
                $value = $source->getChildren($variant)[0] ?? null;
            } elseif ($variant->kind === NodeKind::KeyValueArrayElement) {
                $value = $source->getChildren($variant)[1] ?? null;
            } else {
                return null;
            }
            if ($value === null) {
                return null;
            }
            $nodes[] = $value;
        }

        return $nodes;
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
        foreach ($type->atomicTypes as $atomic) {
            if (self::isUncheckableAtomic($atomic)) {
                return false;
            }
        }

        return true;
    }

    private static function containsMixedOrTemplate(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if (self::isUncheckableAtomic($atomic)) {
                return true;
            }
        }

        return false;
    }

    private static function isUncheckableAtomic(object $atomic): bool
    {
        if (
            $atomic instanceof MixedType
            || $atomic instanceof GenericParameterType
            || $atomic instanceof ConditionalType
            || $atomic instanceof DerivedType
            || $atomic instanceof ReferenceType
            || $atomic instanceof AliasType
            || $atomic instanceof VariableType
        ) {
            return true;
        }

        if ($atomic instanceof NamedObjectType) {
            $lower = strtolower($atomic->name);
            if ($lower === 'self' || $lower === 'static' || $lower === 'parent') {
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
                || $node->kind === NodeKind::Variable
            )
        ) {
            $node = $source->getChildren($node)[0] ?? null;
        }

        return $node;
    }
}
