<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use AlfredBez\MagoPhpunit\Analyzer\PhpUnitVersion;
use JsonException;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\ClassLikeAnalysisHook;
use Mago\Sdk\Analyzer\ClassLikeTarget;
use Mago\Sdk\Analyzer\FileAnalysis;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\LifecycleContext;
use Mago\Sdk\Analyzer\Metadata\AttributeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\MethodFields;
use Mago\Sdk\Analyzer\Metadata\ParameterMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use Mago\Sdk\Syntax\TriviaKind;
use WeakMap;

/**
 * Reports data-provider, TestWith, and TestWithJson rows whose arity or argument types cannot match the test method.
 *
 * Method metadata only keeps the declared return type (`array`, `iterable`), so
 * row shapes are read from the inferred type of each literal return or yield.
 */
final class DataProviderArguments implements ClassLikeAnalysisHook, AfterAnalysisHook
{
    private const DATA_PROVIDER = 'phpunit\\framework\\attributes\\dataprovider';

    private const DATA_PROVIDER_CLOSURE = 'phpunit\\framework\\attributes\\dataproviderclosure';

    private const DATA_PROVIDER_EXTERNAL = 'phpunit\\framework\\attributes\\dataproviderexternal';

    private const TEST_WITH = 'phpunit\\framework\\attributes\\testwith';

    private const TEST_WITH_JSON = 'phpunit\\framework\\attributes\\testwithjson';

    private const TEST_CASE = 'phpunit\\framework\\testcase';

    /** Method dependencies that pass return values into TestCase::$dependencyInput. Class dependencies do not. */
    private const METHOD_DEPENDENCY_ATTRIBUTES = [
        'phpunit\\framework\\attributes\\depends' => true,
        'phpunit\\framework\\attributes\\dependsexternal' => true,
        'phpunit\\framework\\attributes\\dependsusingdeepclone' => true,
        'phpunit\\framework\\attributes\\dependsusingshallowclone' => true,
        'phpunit\\framework\\attributes\\dependsexternalusingdeepclone' => true,
        'phpunit\\framework\\attributes\\dependsexternalusingshallowclone' => true,
    ];

    /** @var WeakMap<SourceFile, array<string, array<string, Node>>> */
    private WeakMap $methodsByFile;

    /** @var WeakMap<SourceFile, array<int, list<array{type: Type, span: Span, label: string}>>> */
    private WeakMap $providerRowsByFile;

    public function __construct()
    {
        $this->methodsByFile = new WeakMap();
        $this->providerRowsByFile = new WeakMap();
    }

    public function getTargets(): array
    {
        return [
            ClassLikeTarget::descendantsOf('PHPUnit\\Framework\\TestCase'),
        ];
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
        $className = $this->testCaseName($context);
        if ($className === null) {
            return;
        }

        $source = $context->analysis->getSourceFile();
        $methods = $this->methodsInFile($source);
        $declared = $context->codebase->getClass($className);
        if ($declared === null) {
            return;
        }

        foreach ($declared->methods as $methodName) {
            $node = $methods[strtolower($className)][strtolower($methodName)] ?? null;
            if ($node === null) {
                continue;
            }

            $providers = $this->providerReferences($context, $source, $node, $className);
            $hasTestWith = $this->hasTestWithAttribute($source, $node);
            if ($providers === [] && !$hasTestWith) {
                continue;
            }

            $test = $context->codebase->getMethod($className, $methodName);
            if ($test === null) {
                continue;
            }

            $testLabel = $this->methodLabel($source, $node);
            $schema = $this->parameterSchema($test->parameters, $this->dependencyCount($source, $node));
            foreach ($providers as $provider) {
                $providerNode = $provider['node'] ?? $this->locateMethod($context, $methods, $provider['class'], $provider['method']);
                if ($providerNode === null) {
                    continue;
                }

                $providerLabel = $provider['node'] !== null ? 'closure' : $this->methodLabel($source, $providerNode);
                $this->checkProvider(
                    $context,
                    $context->analysis,
                    $source,
                    $providerNode,
                    $testLabel,
                    'Data provider ' . $providerLabel . '()',
                    $schema,
                    $provider['validateCount'],
                );
            }

            if ($hasTestWith) {
                $this->checkTestWithAttributes($context, $context->analysis->file, $test->attributes, $testLabel, $schema);
            }
        }
    }

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $methods = $context->codebase->findMethods(
            descendantsOf: 'PHPUnit\\Framework\\TestCase',
            withAnyAttribute: ['PHPUnit\\Framework\\Attributes\\DataProviderExternal'],
            fields: MethodFields::NAMES | MethodFields::LOCATIONS | MethodFields::PARAMETERS | MethodFields::ATTRIBUTES,
            declaredOnly: true,
        );

        foreach ($methods as $testMethod) {
            if ($testMethod->location === null || $testMethod->parameters === null || $testMethod->attributes === null) {
                continue;
            }

            $testFile = $testMethod->location->file;
            $testLabel = $testMethod->originalName ?? $testMethod->method->member;
            $schema = $this->parameterSchema(
                $testMethod->parameters,
                $this->dependencyCountFromAttributes($testMethod->attributes),
            );

            foreach ($testMethod->attributes as $attribute) {
                if (strtolower($attribute->name) !== self::DATA_PROVIDER_EXTERNAL) {
                    continue;
                }

                $classArg = $attribute->getArgument(0, 'className');
                $methodArg = $attribute->getArgument(1, 'methodName');
                $providerClass = $classArg?->valueType?->getLiteralClassString() ?? $classArg?->valueType?->getLiteralString();
                $providerMethod = $methodArg?->valueType?->getLiteralString();
                if ($providerClass === null || $providerClass === '' || $providerMethod === null || $providerMethod === '') {
                    continue;
                }

                $declaring = $context->codebase->getDeclaringMethod($providerClass, $providerMethod);
                if ($declaring === null || $declaring->location->file === $testFile) {
                    continue;
                }

                $providerAnalysis = $context->analysis->getFile($declaring->location->file);
                if ($providerAnalysis === null) {
                    continue;
                }

                $providerSource = $providerAnalysis->getSourceFile();
                $providerMethods = $this->methodsInFile($providerSource);
                $declaringClass = $declaring->identifier->class ?? $providerClass;
                $providerNode = $this->locateMethod($context, $providerMethods, $declaringClass, $declaring->originalName);
                if ($providerNode === null) {
                    continue;
                }

                $validateCount = $attribute->getArgument(2, 'validateArgumentCount')?->valueType?->getLiteralBool() ?? true;
                $providerLabel = $this->methodLabel($providerSource, $providerNode);
                $this->checkProvider(
                    $context,
                    $providerAnalysis,
                    $providerSource,
                    $providerNode,
                    $testLabel,
                    'Data provider ' . $providerLabel . '()',
                    $schema,
                    $validateCount,
                );
            }
        }
    }

    private function dependencyCount(SourceFile $source, Node $method): int
    {
        $count = 0;
        foreach ($source->getChildren($method) as $list) {
            if ($list->kind !== NodeKind::AttributeList) {
                continue;
            }
            foreach ($source->getChildren($list) as $attribute) {
                if ($attribute->kind !== NodeKind::Attribute) {
                    continue;
                }
                $name = strtolower($this->attributeName($source, $attribute) ?? '');
                if (isset(self::METHOD_DEPENDENCY_ATTRIBUTES[$name])) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /** @param list<AttributeMetadata> $attributes */
    private function dependencyCountFromAttributes(array $attributes): int
    {
        $count = 0;
        foreach ($attributes as $attribute) {
            if (isset(self::METHOD_DEPENDENCY_ATTRIBUTES[strtolower($attribute->name)])) {
                $count++;
            }
        }

        return $count;
    }

    private function hasTestWithAttribute(SourceFile $source, Node $method): bool
    {
        foreach ($source->getChildren($method) as $list) {
            if ($list->kind !== NodeKind::AttributeList) {
                continue;
            }
            foreach ($source->getChildren($list) as $attribute) {
                if ($attribute->kind !== NodeKind::Attribute) {
                    continue;
                }
                $name = strtolower($this->attributeName($source, $attribute) ?? '');
                if ($name === self::TEST_WITH || $name === self::TEST_WITH_JSON) {
                    return true;
                }
            }
        }

        return false;
    }

    private function testCaseName(NodeAnalysisContext $context): ?string
    {
        foreach ($context->source->getChildren($context->node) as $child) {
            if ($child->kind !== NodeKind::LocalIdentifier) {
                continue;
            }

            $resolved = $context->source->getResolvedName($child);
            if ($resolved === null) {
                return null;
            }

            $class = $context->codebase->getClass($resolved->name);
            if ($class === null) {
                return null;
            }

            $parents = array_map(strtolower(...), $class->parentClasses);
            if (!in_array(self::TEST_CASE, $parents, true)) {
                return null;
            }

            return $resolved->name;
        }

        return null;
    }

    /**
     * @return array<string, array<string, Node>>
     */
    private function methodsInFile(SourceFile $source): array
    {
        if (isset($this->methodsByFile[$source])) {
            return $this->methodsByFile[$source];
        }

        $methods = [];
        foreach ($source->getNodes(NodeKind::Class_) as $classNode) {
            $className = null;
            foreach ($source->getChildren($classNode) as $child) {
                if ($child->kind !== NodeKind::LocalIdentifier) {
                    continue;
                }

                $className = $source->getResolvedName($child)->name ?? null;
                break;
            }
            if ($className === null) {
                continue;
            }

            foreach ($source->getDescendants($classNode, NodeKind::Method) as $method) {
                $label = $this->methodLabel($source, $method);
                $methods[strtolower($className)][strtolower($label)] = $method;
            }
        }

        return $this->methodsByFile[$source] = $methods;
    }

    /**
     * @param array<string, array<string, Node>> $methods
     */
    private function locateMethod(LifecycleContext $context, array $methods, string $class, string $method): ?Node
    {
        $seen = [];
        $current = strtolower($class);
        while ($current !== '' && !isset($seen[$current])) {
            $seen[$current] = true;
            if (isset($methods[$current][strtolower($method)])) {
                return $methods[$current][strtolower($method)];
            }
            if ($current === self::TEST_CASE) {
                break;
            }

            $parent = $context->codebase->getClass($current)?->directParentClass;
            $current = $parent === null ? '' : strtolower($parent);
        }

        return null;
    }

    /**
     * @return list<array{class: string, method: string, node: ?Node, validateCount: bool}>
     */
    private function providerReferences(NodeAnalysisContext $context, SourceFile $source, Node $method, string $className): array
    {
        $references = [];
        foreach ($source->getChildren($method) as $attributeList) {
            if ($attributeList->kind !== NodeKind::AttributeList) {
                continue;
            }

            foreach ($source->getChildren($attributeList) as $attribute) {
                if ($attribute->kind !== NodeKind::Attribute) {
                    continue;
                }

                $name = $this->attributeName($source, $attribute);
                if ($name === null) {
                    continue;
                }

                $normalized = strtolower($name);
                $arguments = $this->attributeArguments($source, $attribute);
                if ($normalized === self::DATA_PROVIDER) {
                    $provider = $this->literalString(
                        $source,
                        $this->attributeArgument($source, $arguments, 0, 'methodName'),
                    );
                    if ($provider === null || $provider === '') {
                        continue;
                    }

                    $references[] = [
                        'class' => $className,
                        'method' => $provider,
                        'node' => null,
                        'validateCount' => $this->validateArgumentCount(
                            $source,
                            $this->attributeArgument($source, $arguments, 1, 'validateArgumentCount'),
                        ),
                    ];
                    continue;
                }

                if ($normalized === self::DATA_PROVIDER_CLOSURE) {
                    if (!PhpUnitVersion::supportsDataProviderClosure($context->codebase)) {
                        continue;
                    }

                    $closure = $this->closureArgument(
                        $source,
                        $this->attributeArgument($source, $arguments, 0, 'closure'),
                    );
                    if ($closure === null) {
                        continue;
                    }

                    $references[] = [
                        'class' => '',
                        'method' => '',
                        'node' => $closure,
                        'validateCount' => $this->validateArgumentCount(
                            $source,
                            $this->attributeArgument($source, $arguments, 1, 'validateArgumentCount'),
                        ),
                    ];
                    continue;
                }

                if ($normalized !== self::DATA_PROVIDER_EXTERNAL) {
                    continue;
                }

                $classArgument = $this->attributeArgument($source, $arguments, 0, 'className');
                $methodArgument = $this->attributeArgument($source, $arguments, 1, 'methodName');
                if ($classArgument === null || $methodArgument === null) {
                    continue;
                }

                $providerClass = $this->referencedClass($context, $source, $classArgument);
                $provider = $this->literalString($source, $methodArgument);
                if ($providerClass === null || $provider === null || $provider === '') {
                    continue;
                }

                $references[] = [
                    'class' => $providerClass,
                    'method' => $provider,
                    'node' => null,
                    'validateCount' => $this->validateArgumentCount(
                        $source,
                        $this->attributeArgument($source, $arguments, 2, 'validateArgumentCount'),
                    ),
                ];
            }
        }

        if (!PhpUnitVersion::supportsDocblockMetadata($context->codebase)) {
            return $references;
        }

        $docblock = $this->attachedDocblock($source, $method);
        if ($docblock === null) {
            return $references;
        }

        $matched = preg_match_all(
            '/^[ \t]*\*?[ \t]*@dataProvider[ \t]+(\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*)(?:::([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*))?/m',
            $docblock,
            $matches,
            PREG_SET_ORDER,
        );
        if ($matched === false) {
            return $references;
        }

        foreach ($matches as $match) {
            $resolved = $this->docblockProvider($context, $className, $match[1], $match[2] ?? '');
            if ($resolved === null) {
                continue;
            }

            $references[] = [
                'class' => $resolved['class'],
                'method' => $resolved['method'],
                'node' => null,
                'validateCount' => true,
            ];
        }

        return $references;
    }

    /**
     * @return array{class: string, method: string}|null
     */
    private function docblockProvider(NodeAnalysisContext $context, string $testClass, string $target, string $method): ?array
    {
        if ($method === '') {
            return ['class' => $testClass, 'method' => $target];
        }

        $class = str_starts_with($target, '\\') ? substr($target, 1) : $target;
        if ($context->codebase->classLikeExists($class)) {
            return ['class' => $class, 'method' => $method];
        }

        $namespace = strrpos($testClass, '\\');
        $qualified = $namespace === false ? $class : substr($testClass, 0, $namespace) . '\\' . $class;
        if ($context->codebase->classLikeExists($qualified)) {
            return ['class' => $qualified, 'method' => $method];
        }

        return null;
    }

    /**
     * @param array{
     *     required: int,
     *     accepted: int,
     *     variadic: bool,
     *     names: list<string>,
     *     requiredNames: list<string>,
     *     positional: list<array{name: string, type: ?Type}>,
     *     named: array<string, ?Type>,
     *     variadicParameter: ?array{name: string, type: ?Type},
     *     dependencies: int,
     * } $schema
     */
    private function checkProvider(
        LifecycleContext $context,
        FileAnalysis $analysis,
        SourceFile $source,
        Node $provider,
        string $test,
        string $sourceLabel,
        array $schema,
        bool $validateCount,
    ): void {
        foreach ($this->extractProviderRows($analysis, $source, $provider) as $row) {
            $this->checkRow($context, $analysis->file, $row['type'], $row['span'], $row['label'], $test, $sourceLabel, $schema, $validateCount);
        }
    }

    /**
     * @return list<array{type: Type, span: Span, label: string}>
     */
    private function extractProviderRows(FileAnalysis $analysis, SourceFile $source, Node $provider): array
    {
        $cached = $this->providerRowsByFile[$source] ?? [];
        if (isset($cached[$provider->id])) {
            return $cached[$provider->id];
        }

        $rows = [];
        $yields = [];
        foreach ($source->getDescendants($provider, NodeKind::Yield) as $yield) {
            if ($this->isNestedCallable($source, $provider, $yield)) {
                continue;
            }
            $yields[] = $yield;
        }

        if ($yields !== []) {
            foreach ($yields as $index => $yield) {
                $collection = $this->yieldFromArrayExpression($source, $yield);
                if ($collection !== null) {
                    $type = $analysis->getExpressionType($collection);
                    if ($type === null) {
                        continue;
                    }

                    foreach ($this->rowsFromCollection($source, $collection, $type) as $row) {
                        $rows[] = $row;
                    }

                    continue;
                }

                $expression = $this->valueExpression($source, $yield);
                if ($expression === null) {
                    continue;
                }

                $type = $analysis->getExpressionType($expression);
                if ($type === null) {
                    continue;
                }

                $rows[] = [
                    'type' => $type,
                    'span' => $expression->span,
                    'label' => '#' . $index,
                ];
            }

            $cached[$provider->id] = $rows;
            $this->providerRowsByFile[$source] = $cached;

            return $rows;
        }

        foreach ($source->getDescendants($provider, NodeKind::Return) as $return) {
            if ($this->isNestedCallable($source, $provider, $return)) {
                continue;
            }

            $expression = $this->valueExpression($source, $return);
            if ($expression === null) {
                continue;
            }

            $type = $analysis->getExpressionType($expression);
            if ($type === null) {
                continue;
            }

            foreach ($this->rowsFromCollection($source, $expression, $type) as $row) {
                $rows[] = $row;
            }
        }

        $cached[$provider->id] = $rows;
        $this->providerRowsByFile[$source] = $cached;

        return $rows;
    }

    /**
     * @param list<AttributeMetadata> $attributes
     * @param array{
     *     required: int,
     *     accepted: int,
     *     variadic: bool,
     *     names: list<string>,
     *     requiredNames: list<string>,
     *     positional: list<array{name: string, type: ?Type}>,
     *     named: array<string, ?Type>,
     *     variadicParameter: ?array{name: string, type: ?Type},
     *     dependencies: int,
     * } $schema
     */
    private function checkTestWithAttributes(
        LifecycleContext $context,
        string $reportFile,
        array $attributes,
        string $test,
        array $schema,
    ): void {
        $index = 0;
        foreach ($attributes as $attribute) {
            $normalized = strtolower($attribute->name);
            if ($normalized !== self::TEST_WITH && $normalized !== self::TEST_WITH_JSON) {
                continue;
            }

            $nameArg = $attribute->getArgument(1, 'name');
            $datasetName = $nameArg?->valueType?->getLiteralString();
            $label = $datasetName !== null && $datasetName !== '' ? '"' . $datasetName . '"' : '#' . $index;
            $index++;

            if ($normalized === self::TEST_WITH) {
                $dataArg = $attribute->getArgument(0, 'data');
                $type = $dataArg?->valueType;
                if ($type === null) {
                    continue;
                }

                $span = $dataArg->valueLocation?->span ?? $attribute->location->span;
                $this->checkRow($context, $reportFile, $type, $span, $label, $test, 'Attribute #[TestWith]', $schema, true);
                continue;
            }

            $jsonArg = $attribute->getArgument(0, 'json');
            $json = $jsonArg?->valueType?->getLiteralString();
            if ($json === null) {
                continue;
            }

            $span = $jsonArg->valueLocation?->span ?? $attribute->location->span;
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->report($context, $reportFile, $span, sprintf(
                    'Attribute #[TestWithJson] row %s for %s() contains invalid JSON.',
                    $label,
                    $test,
                ));
                continue;
            }

            if (!is_array($decoded)) {
                $this->report($context, $reportFile, $span, sprintf(
                    'Attribute #[TestWithJson] row %s provides %s for %s(), which expects an argument list.',
                    $label,
                    get_debug_type($decoded),
                    $test,
                ));
                continue;
            }

            $this->checkRow(
                $context,
                $reportFile,
                self::jsonValueToType($decoded),
                $span,
                $label,
                $test,
                'Attribute #[TestWithJson]',
                $schema,
                true,
            );
        }
    }

    private static function jsonValueToType(mixed $value): Type
    {
        if (is_int($value)) {
            return Type::literalInt($value);
        }
        if (is_float($value)) {
            return Type::float();
        }
        if (is_string($value)) {
            return Type::literalString($value);
        }
        if (is_bool($value)) {
            return $value ? Type::true() : Type::false();
        }
        if ($value === null) {
            return Type::null();
        }
        if (!is_array($value)) {
            return Type::mixed();
        }
        if ($value === []) {
            return Type::fromAtomic(new ListType(Type::never(), [], 0, false));
        }

        if (array_is_list($value)) {
            $elements = [];
            foreach ($value as $index => $item) {
                $elements[] = new ListElement($index, false, self::jsonValueToType($item));
            }

            return Type::fromAtomic(new ListType(Type::mixed(), $elements, count($elements), true));
        }

        $items = [];
        foreach ($value as $key => $item) {
            $arrayKey = is_int($key)
                ? new ArrayKey(ArrayKeyKind::Integer, $key)
                : new ArrayKey(ArrayKeyKind::String, (string) $key);
            $items[] = new ArrayItem($arrayKey, false, self::jsonValueToType($item));
        }

        return Type::fromAtomic(new KeyedArrayType($items, null, null, true));
    }

    /**
     * @return list<array{type: Type, span: Span, label: string}>
     */
    private function rowsFromCollection(SourceFile $source, Node $expression, Type $type): array
    {
        if (count($type->atomicTypes) !== 1) {
            return [];
        }

        $atomic = $type->atomicTypes[0];
        if ($atomic instanceof ListType) {
            $rows = [];
            foreach ($atomic->knownElements ?? [] as $element) {
                if ($element->optional) {
                    continue;
                }

                $rows[] = [
                    'type' => $element->type,
                    'span' => $this->listElementSpan($source, $expression, $element->index),
                    'label' => '#' . $element->index,
                ];
            }

            return $rows;
        }

        if (!$atomic instanceof KeyedArrayType || $atomic->knownItems === null) {
            return [];
        }

        $rows = [];
        foreach ($atomic->knownItems as $item) {
            if ($item->optional) {
                continue;
            }

            $label = match ($item->key->kind) {
                ArrayKeyKind::String => '"' . $item->key->value . '"',
                ArrayKeyKind::Integer => '#' . $item->key->value,
                ArrayKeyKind::ClassLikeConstant => null,
            };
            if ($label === null) {
                continue;
            }

            $rows[] = [
                'type' => $item->type,
                'span' => $expression->span,
                'label' => $label,
            ];
        }

        return $rows;
    }

    /**
     * @param array{
     *     required: int,
     *     accepted: int,
     *     variadic: bool,
     *     names: list<string>,
     *     requiredNames: list<string>,
     *     positional: list<array{name: string, type: ?Type}>,
     *     named: array<string, ?Type>,
     *     variadicParameter: ?array{name: string, type: ?Type},
     *     dependencies: int,
     * } $schema
     */
    private function checkRow(
        LifecycleContext $context,
        string $reportFile,
        Type $type,
        Span $span,
        string $label,
        string $test,
        string $sourceLabel,
        array $schema,
        bool $validateCount,
    ): void {
        $named = $this->namedArguments($type);
        if ($named !== null) {
            if (!PhpUnitVersion::supportsNamedProviderArguments($context->codebase)) {
                $this->checkPositionalArguments(
                    $context,
                    $reportFile,
                    array_values($named),
                    $span,
                    $label,
                    $test,
                    $sourceLabel,
                    $schema,
                    $validateCount,
                );

                return;
            }

            if ($schema['dependencies'] > 0) {
                $this->report($context, $reportFile, $span, sprintf(
                    '%s row %s uses named arguments with dependencies for %s(), which fails when PHP unpacks positional dependency results after named arguments.',
                    $sourceLabel,
                    $label,
                    $test,
                ));

                return;
            }

            $names = array_keys($named);
            $missing = [];
            foreach ($schema['requiredNames'] as $name) {
                if (!in_array($name, $names, true)) {
                    $missing[] = '$' . $name;
                }
            }

            $known = $schema['names'];
            $unknown = [];
            if (!$schema['variadic']) {
                foreach ($names as $name) {
                    if (!in_array($name, $known, true)) {
                        $unknown[] = '$' . $name;
                    }
                }
            }

            if ($missing !== []) {
                $this->report($context, $reportFile, $span, sprintf(
                    '%s row %s does not provide %s for %s().',
                    $sourceLabel,
                    $label,
                    implode(', ', $missing),
                    $test,
                ));
            }
            if ($unknown !== []) {
                $this->report($context, $reportFile, $span, sprintf(
                    '%s row %s provides unknown argument %s for %s().',
                    $sourceLabel,
                    $label,
                    implode(', ', $unknown),
                    $test,
                ));
            }

            foreach ($named as $name => $argumentType) {
                if (array_key_exists($name, $schema['named'])) {
                    $parameterName = $name;
                    $parameterType = $schema['named'][$name];
                } elseif ($schema['variadicParameter'] !== null) {
                    $parameterName = $schema['variadicParameter']['name'];
                    $parameterType = $schema['variadicParameter']['type'];
                } else {
                    continue;
                }
                if ($parameterType === null) {
                    continue;
                }

                $this->checkArgumentType(
                    $context,
                    $reportFile,
                    $span,
                    $label,
                    $test,
                    $sourceLabel,
                    $parameterName,
                    $parameterType,
                    $argumentType,
                );
            }

            return;
        }

        $arguments = $this->positionalArguments($type);
        if ($arguments === null) {
            if ($this->isDefinitelyNotArray($type)) {
                $this->report($context, $reportFile, $span, sprintf(
                    '%s row %s provides %s for %s(), which expects an argument list.',
                    $sourceLabel,
                    $label,
                    (string) $type,
                    $test,
                ));
            }

            return;
        }

        $this->checkPositionalArguments($context, $reportFile, $arguments, $span, $label, $test, $sourceLabel, $schema, $validateCount);
    }

    /**
     * @param list<Type> $arguments
     * @param array{
     *     required: int,
     *     accepted: int,
     *     variadic: bool,
     *     names: list<string>,
     *     requiredNames: list<string>,
     *     positional: list<array{name: string, type: ?Type}>,
     *     named: array<string, ?Type>,
     *     variadicParameter: ?array{name: string, type: ?Type},
     *     dependencies: int,
     * } $schema
     */
    private function checkPositionalArguments(
        LifecycleContext $context,
        string $reportFile,
        array $arguments,
        Span $span,
        string $label,
        string $test,
        string $sourceLabel,
        array $schema,
        bool $validateCount,
    ): void {
        $count = count($arguments);
        if ($count < $schema['required']) {
            $this->report($context, $reportFile, $span, sprintf(
                '%s row %s provides %d %s for %s(), which requires %d.',
                $sourceLabel,
                $label,
                $count,
                $count === 1 ? 'argument' : 'arguments',
                $test,
                $schema['required'],
            ));
        } elseif ($validateCount && !$schema['variadic'] && $count > $schema['accepted']) {
            $this->report($context, $reportFile, $span, sprintf(
                '%s row %s provides %d arguments for %s(), which accepts %d.',
                $sourceLabel,
                $label,
                $count,
                $test,
                $schema['accepted'],
            ));
        }

        foreach ($arguments as $index => $argumentType) {
            $parameter = $schema['positional'][$index] ?? $schema['variadicParameter'];
            if ($parameter === null || $parameter['type'] === null) {
                continue;
            }

            $this->checkArgumentType(
                $context,
                $reportFile,
                $span,
                $label,
                $test,
                $sourceLabel,
                $parameter['name'],
                $parameter['type'],
                $argumentType,
            );
        }
    }

    private function checkArgumentType(
        LifecycleContext $context,
        string $reportFile,
        Span $span,
        string $label,
        string $test,
        string $sourceLabel,
        string $parameterName,
        Type $parameterType,
        Type $argumentType,
    ): void {
        if (!$this->isCheckableType($argumentType)) {
            return;
        }
        if ($context->types->isContainedBy($argumentType, $parameterType)) {
            return;
        }

        $this->report($context, $reportFile, $span, sprintf(
            '%s row %s provides %s for $%s of %s(), which expects %s.',
            $sourceLabel,
            $label,
            (string) $argumentType,
            $parameterName,
            $test,
            (string) $parameterType,
        ));
    }

    /**
     * @param list<ParameterMetadata> $parameters
     * @return array{
     *     dependencies: int,
     *     required: int,
     *     accepted: int,
     *     variadic: bool,
     *     names: list<string>,
     *     requiredNames: list<string>,
     *     positional: list<array{name: string, type: ?Type}>,
     *     named: array<string, ?Type>,
     *     variadicParameter: ?array{name: string, type: ?Type},
     * }
     */
    private function parameterSchema(array $parameters, int $dependencies): array
    {
        $required = 0;
        $accepted = 0;
        $variadic = false;
        $names = [];
        $requiredNames = [];
        $positional = [];
        $named = [];
        $variadicParameter = null;
        foreach ($parameters as $parameter) {
            $name = ltrim($parameter->name, '$');
            $type = $this->parameterType($parameter);
            if ($parameter->flags->contains(MetadataFlags::VARIADIC)) {
                $variadic = true;
                $variadicParameter = ['name' => $name, 'type' => $type];
                continue;
            }

            $names[] = $name;
            $positional[] = ['name' => $name, 'type' => $type];
            $named[$name] = $type;
            $accepted++;
            if (!$parameter->flags->contains(MetadataFlags::HAS_DEFAULT)) {
                $required++;
                $requiredNames[] = $name;
            }
        }

        return [
            'required' => max(0, $required - $dependencies),
            'dependencies' => $dependencies,
            'accepted' => $accepted,
            'variadic' => $variadic,
            'names' => $names,
            'requiredNames' => $requiredNames,
            'positional' => $positional,
            'named' => $named,
            'variadicParameter' => $variadicParameter,
        ];
    }

    private function parameterType(ParameterMetadata $parameter): ?Type
    {
        $type = $parameter->declaredType?->type ?? $parameter->type?->type;
        if ($type === null || !$this->isCheckableType($type)) {
            return null;
        }

        return $type;
    }

    private function isCheckableType(Type $type): bool
    {
        if ($type->atomicTypes === []) {
            return false;
        }

        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType) {
                return false;
            }
            if (
                $atomic instanceof SimpleAtomicType
                && (
                    $atomic->kind === SimpleAtomicTypeKind::Never
                    || $atomic->kind === SimpleAtomicTypeKind::Placeholder
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return list<Type>|null */
    private function positionalArguments(Type $type): ?array
    {
        if (count($type->atomicTypes) !== 1) {
            return null;
        }

        $atomic = $type->atomicTypes[0];
        if ($atomic instanceof ListType) {
            if (
                $atomic->knownElements === null
                || $atomic->knownCount === null
                || $atomic->knownCount !== count($atomic->knownElements)
            ) {
                return null;
            }

            $arguments = [];
            foreach ($atomic->knownElements as $element) {
                if ($element->optional) {
                    return null;
                }
                $arguments[$element->index] = $element->type;
            }

            ksort($arguments);
            if (array_keys($arguments) !== ($arguments === [] ? [] : range(0, count($arguments) - 1))) {
                return null;
            }

            return array_values($arguments);
        }

        if (!$atomic instanceof KeyedArrayType || !$this->isClosedShape($atomic)) {
            return null;
        }

        $arguments = [];
        foreach ($atomic->knownItems ?? [] as $item) {
            if ($item->key->kind !== ArrayKeyKind::Integer || !is_int($item->key->value)) {
                return null;
            }
            $arguments[$item->key->value] = $item->type;
        }

        ksort($arguments);
        if (array_keys($arguments) !== ($arguments === [] ? [] : range(0, count($arguments) - 1))) {
            return null;
        }

        return array_values($arguments);
    }

    /** @return array<string, Type>|null */
    private function namedArguments(Type $type): ?array
    {
        if (count($type->atomicTypes) !== 1 || !$type->atomicTypes[0] instanceof KeyedArrayType) {
            return null;
        }

        $shape = $type->atomicTypes[0];
        if (!$this->isClosedShape($shape) || $shape->knownItems === []) {
            return null;
        }

        $arguments = [];
        foreach ($shape->knownItems ?? [] as $item) {
            if ($item->key->kind !== ArrayKeyKind::String || !is_string($item->key->value)) {
                return null;
            }
            $arguments[$item->key->value] = $item->type;
        }

        return $arguments;
    }

    private function isClosedShape(KeyedArrayType $shape): bool
    {
        if ($shape->knownItems === null || $shape->keyType !== null || $shape->valueType !== null) {
            return false;
        }

        foreach ($shape->knownItems as $item) {
            if ($item->optional) {
                return false;
            }
        }

        return true;
    }

    private function isDefinitelyNotArray(Type $type): bool
    {
        if ($type->atomicTypes === []) {
            return false;
        }

        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof ListType || $atomic instanceof KeyedArrayType || $atomic instanceof MixedType) {
                return false;
            }
        }

        return true;
    }

    private function report(LifecycleContext $context, string $reportFile, Span $span, string $message): void
    {
        $context->report(
            Level::Error,
            'phpunit-data-provider',
            Issue::at($message, new SourceLocation($reportFile, $span)),
        );
    }

    private function listElementSpan(SourceFile $source, Node $expression, int $index): Span
    {
        $array = $expression->kind === NodeKind::Array || $expression->kind === NodeKind::LegacyArray
            ? $expression
            : ($source->getFirstDescendant($expression, NodeKind::Array) ?? $source->getFirstDescendant($expression, NodeKind::LegacyArray));
        if ($array === null) {
            return $expression->span;
        }

        $elements = [];
        foreach ($source->getChildren($array) as $child) {
            if ($child->kind === NodeKind::VariadicArrayElement || $child->kind === NodeKind::MissingArrayElement) {
                return $expression->span;
            }
            if ($child->kind === NodeKind::ValueArrayElement || $child->kind === NodeKind::KeyValueArrayElement) {
                $elements[] = $child;
            }
        }

        return $elements[$index]->span ?? $expression->span;
    }

    private function valueExpression(SourceFile $source, Node $node): ?Node
    {
        $expressions = [];
        foreach ($source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::Expression) {
                $expressions[] = $child;
            }
        }
        if ($expressions !== []) {
            return $expressions[array_key_last($expressions)];
        }

        foreach ($source->getChildren($node) as $child) {
            if ($child->kind === NodeKind::YieldValue || $child->kind === NodeKind::YieldPair) {
                return $this->valueExpression($source, $child);
            }
        }

        return null;
    }

    private function yieldFromArrayExpression(SourceFile $source, Node $node): ?Node
    {
        foreach ($source->getChildren($node) as $child) {
            if ($child->kind !== NodeKind::YieldFrom) {
                continue;
            }

            $expression = $this->valueExpression($source, $child);
            if ($expression === null || !$this->isLiteralArray($source, $expression)) {
                return null;
            }

            return $expression;
        }

        return null;
    }

    private function isLiteralArray(SourceFile $source, Node $expression): bool
    {
        if ($expression->kind === NodeKind::Array || $expression->kind === NodeKind::LegacyArray) {
            return true;
        }

        foreach ($source->getChildren($expression) as $child) {
            if ($child->kind === NodeKind::Array || $child->kind === NodeKind::LegacyArray) {
                return true;
            }
        }

        return false;
    }

    private function isNestedCallable(SourceFile $source, Node $provider, Node $node): bool
    {
        $current = $node;
        while ($current !== $provider) {
            $parent = $source->getParent($current);
            if ($parent === null || $parent === $provider) {
                return false;
            }
            if (
                $parent->kind === NodeKind::Closure
                || $parent->kind === NodeKind::ArrowFunction
                || $parent->kind === NodeKind::Function
                || $parent->kind === NodeKind::AnonymousClass
            ) {
                return true;
            }
            $current = $parent;
        }

        return false;
    }

    private function methodLabel(SourceFile $source, Node $method): string
    {
        foreach ($source->getChildren($method) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                return $source->getText($child);
            }
        }

        return '';
    }

    private function attributeName(SourceFile $source, Node $attribute): ?string
    {
        foreach ($source->getChildren($attribute) as $child) {
            $resolved = $source->getResolvedName($child);
            if ($resolved !== null) {
                return $resolved->name;
            }
        }

        return null;
    }

    /** @return list<Node> */
    private function attributeArguments(SourceFile $source, Node $attribute): array
    {
        $arguments = [];
        foreach ($source->getChildren($attribute) as $list) {
            if ($list->kind !== NodeKind::PartialArgumentList && $list->kind !== NodeKind::ArgumentList) {
                continue;
            }

            foreach ($source->getChildren($list) as $item) {
                foreach ($source->getChildren($item) as $argument) {
                    if ($argument->kind === NodeKind::PositionalArgument || $argument->kind === NodeKind::NamedArgument) {
                        $arguments[] = $argument;
                    }
                }
            }
        }

        return $arguments;
    }

    /** @param list<Node> $arguments */
    private function attributeArgument(SourceFile $source, array $arguments, int $position, string $name): ?Node
    {
        foreach ($arguments as $argument) {
            if ($argument->kind === NodeKind::NamedArgument && $this->argumentName($source, $argument) === $name) {
                return $argument;
            }
        }

        $index = 0;
        foreach ($arguments as $argument) {
            if ($argument->kind === NodeKind::NamedArgument) {
                continue;
            }
            if ($index++ === $position) {
                return $argument;
            }
        }

        return null;
    }

    private function argumentName(SourceFile $source, Node $argument): ?string
    {
        foreach ($source->getChildren($argument) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                return $source->getText($child);
            }
        }

        return null;
    }

    private function closureArgument(SourceFile $source, ?Node $argument): ?Node
    {
        if ($argument === null) {
            return null;
        }

        $expression = $this->valueExpression($source, $argument);
        if ($expression === null) {
            return null;
        }

        foreach ($source->getChildren($expression) as $child) {
            if ($child->kind === NodeKind::Closure) {
                foreach ($source->getChildren($child) as $part) {
                    if ($part->kind === NodeKind::ClosureUseClause) {
                        return null;
                    }
                }

                return $child;
            }
        }

        return null;
    }

    private function literalString(SourceFile $source, ?Node $argument): ?string
    {
        if ($argument === null) {
            return null;
        }

        $literal = $argument->kind === NodeKind::LiteralString
            ? $argument
            : $source->getFirstDescendant($argument, NodeKind::LiteralString);
        if ($literal === null) {
            return null;
        }

        return $source->getLiteralString($literal) ?? $this->decodeQuoted($source->getText($literal));
    }

    private function decodeQuoted(string $text): ?string
    {
        if (strlen($text) < 2) {
            return null;
        }

        $quote = $text[0];
        if (($quote !== "'" && $quote !== '"') || $text[strlen($text) - 1] !== $quote) {
            return null;
        }

        $body = substr($text, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ['\\', "'"], $body);
        }
        if (str_contains($body, '$')) {
            return null;
        }

        return stripcslashes($body);
    }

    private function validateArgumentCount(SourceFile $source, ?Node $argument): bool
    {
        if ($argument === null) {
            return true;
        }

        $value = $this->valueExpression($source, $argument) ?? $argument;
        $text = strtolower(trim($source->getText($value)));

        return $text !== 'false';
    }

    private function referencedClass(NodeAnalysisContext $context, SourceFile $source, Node $argument): ?string
    {
        foreach ($source->getResolvedNames($argument) as $resolved) {
            if ($context->codebase->classLikeExists($resolved->name)) {
                return $resolved->name;
            }
        }

        return null;
    }

    private function attachedDocblock(SourceFile $source, Node $method): ?string
    {
        $chosen = null;
        foreach ($source->getTrivia() as $trivia) {
            if ($trivia->kind !== TriviaKind::DocBlockComment || $trivia->span->end > $method->span->start) {
                continue;
            }
            if ($chosen === null || $trivia->span->end > $chosen->span->end) {
                $chosen = $trivia;
            }
        }
        if ($chosen === null) {
            return null;
        }

        $between = substr($source->contents, $chosen->span->end, $method->span->start - $chosen->span->end);
        if ($between !== '' && preg_match('/\S/', $between) === 1) {
            return null;
        }

        return $source->getText($chosen->span);
    }
}
