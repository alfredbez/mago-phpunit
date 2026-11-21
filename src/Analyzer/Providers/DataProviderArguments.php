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
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\MethodFields;
use Mago\Sdk\Analyzer\Metadata\ParameterMetadata;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\FloatType;
use Mago\Sdk\Analyzer\Type\FloatTypeKind;
use Mago\Sdk\Analyzer\Type\IntegerType;
use Mago\Sdk\Analyzer\Type\IntegerTypeKind;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;
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

    /** Same-class method dependencies that pass return values into TestCase::$dependencyInput. */
    private const SAME_CLASS_DEPENDENCY_ATTRIBUTES = [
        'phpunit\\framework\\attributes\\depends' => true,
        'phpunit\\framework\\attributes\\dependsusingdeepclone' => true,
        'phpunit\\framework\\attributes\\dependsusingshallowclone' => true,
    ];

    /** External method dependencies that pass return values into TestCase::$dependencyInput. */
    private const EXTERNAL_DEPENDENCY_ATTRIBUTES = [
        'phpunit\\framework\\attributes\\dependsexternal' => true,
        'phpunit\\framework\\attributes\\dependsexternalusingdeepclone' => true,
        'phpunit\\framework\\attributes\\dependsexternalusingshallowclone' => true,
    ];

    /** Method dependencies that pass return values into TestCase::$dependencyInput. Class dependencies do not. */
    private const METHOD_DEPENDENCY_ATTRIBUTES = self::SAME_CLASS_DEPENDENCY_ATTRIBUTES + self::EXTERNAL_DEPENDENCY_ATTRIBUTES;

    /** @var WeakMap<SourceFile, array<string, array<string, Node>>> */
    private WeakMap $methodsByFile;

    /** @var WeakMap<SourceFile, array<int, list<array{type: Type, span: Span, label: string, keyOrder: ?list<string>}>>> */
    private WeakMap $providerRowsByFile;

    /** @var array<string, array<string, array{file: string, label: string, rows: list<array{type: Type, span: Span, label: string, keyOrder: ?list<string>}>}>> */
    private array $providerRowsByMethod = [];

    /**
     * @var list<array{
     *     testFile: string,
     *     testLabel: string,
     *     providerClass: string,
     *     providerMethod: string,
     *     validateCount: bool,
     *     schema: array{
     *         required: int,
     *         accepted: int,
     *         variadic: bool,
     *         names: list<string>,
     *         requiredNames: list<string>,
     *         positional: list<array{name: string, type: ?Type}>,
     *         named: array<string, ?Type>,
     *         variadicParameter: ?array{name: string, type: ?Type},
     *         dependencies: int,
     *     },
     *     dependencies: list<array{class: ?string, method: ?string, span: Span, external: bool}>,
     * }>
     */
    private array $pendingExternalProviders = [];

    private bool $sawAnalyzeCall = false;

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
        $this->sawAnalyzeCall = true;
        $contents = $context->source->contents;
        if (
            stripos($contents, 'dataprovider') === false
            && stripos($contents, 'testwith') === false
            && stripos($contents, 'depends') === false
        ) {
            return;
        }

        $className = $this->classNodeName($context->source, $context->node);
        if ($className === null) {
            return;
        }

        $source = $context->source;
        $classMethods = $this->methodsInClass($source, $context->node);
        if ($classMethods === []) {
            return;
        }

        $supportsDocblock = PhpUnitVersion::supportsDocblockMetadata($context->codebase);
        $supportsClosure = PhpUnitVersion::supportsDataProviderClosure($context->codebase);
        $needsKeyOrder = !PhpUnitVersion::supportsNamedProviderArguments($context->codebase);
        $fileHasParamDocblock = stripos($contents, '@param') !== false;
        $lowerClassName = strtolower($className);
        $workItems = [];
        $methodQueries = [];
        foreach ($classMethods as $methodKey => $entry) {
            $node = $entry['node'];
            if (!$supportsDocblock) {
                $attrPos = strpos($contents, '#[', $node->span->start);
                if ($attrPos === false || $attrPos >= $node->span->end) {
                    continue;
                }
            }
            [$providers, $hasTestWith, $dependencies] = $this->extractMethodAnnotations(
                $context,
                $source,
                $node,
                $className,
                $supportsClosure,
                $supportsDocblock,
            );
            if ($providers === [] && !$hasTestWith && $dependencies === []) {
                continue;
            }

            $astSchemaBase = null;
            if (!$hasTestWith) {
                $hasParamDoc = $fileHasParamDocblock
                    && stripos($this->attachedDocblock($source, $node) ?? '', '@param') !== false;
                if (!$hasParamDoc) {
                    $astSchemaBase = $this->astParameterSchema($source, $node, 0);
                }
            }

            $workItems[] = [
                'methodKey' => $methodKey,
                'label' => $entry['label'],
                'node' => $node,
                'providers' => $providers,
                'hasTestWith' => $hasTestWith,
                'dependencies' => $dependencies,
                'astSchema' => $astSchemaBase,
            ];
            if ($astSchemaBase === null) {
                $methodQueries[$lowerClassName . "\0" . $methodKey] = new MemberIdentifier($className, $entry['label']);
            }
            foreach ($dependencies as $dependency) {
                if ($dependency['class'] !== null && $dependency['method'] !== null) {
                    $depKey = strtolower($dependency['class']) . "\0" . strtolower($dependency['method']);
                    $methodQueries[$depKey] ??= new MemberIdentifier($dependency['class'], $dependency['method']);
                }
            }
        }

        if ($workItems === []) {
            return;
        }

        if ($methodQueries !== []) {
            $context->codebase->getMultipleMethods(array_values($methodQueries));
        }

        $fullSource = null;
        $fileMethods = null;
        foreach ($workItems as $item) {
            $testLabel = $item['label'];
            $effectiveDependencies = $this->valuePassingDependencies($context, $item['dependencies']);
            $test = null;
            if ($item['astSchema'] !== null) {
                $schema = $item['astSchema'];
                $depCount = count($effectiveDependencies);
                if ($depCount > 0) {
                    $schema['dependencies'] = $depCount;
                    $schema['required'] = max(0, $schema['required'] - $depCount);
                }
            } else {
                $test = $context->codebase->getMethod($className, $item['label']);
                if ($test === null) {
                    continue;
                }
                $schema = $this->parameterSchema($test->parameters, count($effectiveDependencies));
            }
            $checkedDepOffsets = [];

            if ($item['providers'] === [] && !$item['hasTestWith']) {
                $this->checkDependencyTypes(
                    $context,
                    $context->analysis->file,
                    $effectiveDependencies,
                    0,
                    $testLabel,
                    $schema,
                    $checkedDepOffsets,
                );
                continue;
            }

            foreach ($item['providers'] as $provider) {
                $providerSource = $source;
                $providerClassKey = strtolower($provider['class']);
                $providerMethodKey = strtolower($provider['method']);
                if ($provider['node'] !== null) {
                    $providerNode = $provider['node'];
                } elseif ($providerClassKey === $lowerClassName && isset($classMethods[$providerMethodKey])) {
                    $providerNode = $classMethods[$providerMethodKey]['node'];
                } else {
                    $providerNode = null;
                    if (preg_match('/\bfunction\s+&?\s*' . preg_quote($provider['method'], '/') . '\b/i', $contents) === 1) {
                        if ($fullSource === null) {
                            $fullSource = $context->analysis->getSourceFile();
                            $fileMethods = $this->methodsInFile($fullSource);
                        }
                        $providerSource = $fullSource;
                        $providerNode = $this->locateMethod($context, $fileMethods, $provider['class'], $provider['method']);
                    }
                    if ($providerNode === null && $provider['external']) {
                        $this->pendingExternalProviders[] = [
                            'testFile' => $context->analysis->file,
                            'testLabel' => $testLabel,
                            'providerClass' => $provider['class'],
                            'providerMethod' => $provider['method'],
                            'validateCount' => $provider['validateCount'],
                            'schema' => $schema,
                            'dependencies' => $effectiveDependencies,
                        ];
                    }
                }

                if ($providerNode === null) {
                    continue;
                }

                $providerLabel = $provider['node'] !== null ? 'closure' : $this->methodLabel($providerSource, $providerNode);
                $rows = $this->extractProviderRows($context->analysis, $providerSource, $providerNode, $needsKeyOrder);
                if ($provider['node'] === null) {
                    $this->providerRowsByMethod[$providerClassKey][$providerMethodKey] = [
                        'file' => $context->analysis->file,
                        'label' => $providerLabel,
                        'rows' => $rows,
                    ];
                }
                foreach ($rows as $row) {
                    $this->checkRow(
                        $context,
                        $context->analysis->file,
                        $context->analysis->file,
                        $row['type'],
                        $row['span'],
                        $row['label'],
                        $testLabel,
                        'Data provider ' . $providerLabel . '()',
                        $schema,
                        $provider['validateCount'],
                        $effectiveDependencies,
                        $checkedDepOffsets,
                        $row['keyOrder'],
                    );
                }
            }

            if ($item['hasTestWith'] && $test !== null) {
                $this->checkTestWithAttributes(
                    $context,
                    $context->analysis->file,
                    $source,
                    $item['node'],
                    $test->attributes,
                    $testLabel,
                    $schema,
                    $effectiveDependencies,
                    $checkedDepOffsets,
                    $needsKeyOrder,
                );
            }
        }
    }

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $needsKeyOrder = !PhpUnitVersion::supportsNamedProviderArguments($context->codebase);

        if ($this->sawAnalyzeCall) {
            if ($this->pendingExternalProviders === []) {
                return;
            }

            $pending = $this->pendingExternalProviders;
            $this->pendingExternalProviders = [];

            $uncachedQueries = [];
            foreach ($pending as $entry) {
                $classKey = strtolower($entry['providerClass']);
                $methodKey = strtolower($entry['providerMethod']);
                if (!isset($this->providerRowsByMethod[$classKey][$methodKey])) {
                    $uncachedQueries[$classKey . "\0" . $methodKey] = new MemberIdentifier($entry['providerClass'], $entry['providerMethod']);
                }
            }
            if ($uncachedQueries !== []) {
                $context->codebase->getMultipleDeclaringMethods(array_values($uncachedQueries));
            }

            foreach ($pending as $entry) {
                $classKey = strtolower($entry['providerClass']);
                $methodKey = strtolower($entry['providerMethod']);
                $cached = $this->providerRowsByMethod[$classKey][$methodKey] ?? null;

                if ($cached === null) {
                    $declaring = $context->codebase->getDeclaringMethod($entry['providerClass'], $entry['providerMethod']);
                    if ($declaring === null || $declaring->location->file === $entry['testFile']) {
                        continue;
                    }

                    $providerAnalysis = $context->analysis->getFile($declaring->location->file);
                    if ($providerAnalysis === null) {
                        continue;
                    }

                    $providerSource = $providerAnalysis->getSourceFile();
                    $providerMethods = $this->methodsInFile($providerSource);
                    $declaringClass = $declaring->identifier->class ?? $entry['providerClass'];
                    $providerNode = $this->locateMethod($context, $providerMethods, $declaringClass, $declaring->originalName);
                    if ($providerNode === null) {
                        continue;
                    }

                    $providerLabel = $this->methodLabel($providerSource, $providerNode);
                    $cached = [
                        'file' => $providerAnalysis->file,
                        'label' => $providerLabel,
                        'rows' => $this->extractProviderRows($providerAnalysis, $providerSource, $providerNode, $needsKeyOrder),
                    ];
                    $this->providerRowsByMethod[$classKey][$methodKey] = $cached;
                }

                $checkedDepOffsets = [];
                foreach ($cached['rows'] as $row) {
                    $this->checkRow(
                        $context,
                        $cached['file'],
                        $entry['testFile'],
                        $row['type'],
                        $row['span'],
                        $row['label'],
                        $entry['testLabel'],
                        'Data provider ' . $cached['label'] . '()',
                        $entry['schema'],
                        $entry['validateCount'],
                        $entry['dependencies'],
                        $checkedDepOffsets,
                        $row['keyOrder'],
                    );
                }
            }

            return;
        }

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
            $dependencies = $this->valuePassingDependencies(
                $context,
                $this->dependencyReferencesFromAttributes($testMethod->attributes, $testMethod->method->class),
            );
            $schema = $this->parameterSchema(
                $testMethod->parameters,
                count($dependencies),
            );
            $checkedDepOffsets = [];

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
                    $testFile,
                    $providerSource,
                    $providerNode,
                    $testLabel,
                    'Data provider ' . $providerLabel . '()',
                    $schema,
                    $validateCount,
                    $dependencies,
                    $checkedDepOffsets,
                    $needsKeyOrder,
                );
            }
        }
    }

    /**
     * Single-pass extraction of data providers, TestWith presence, and method dependencies on one method node.
     *
     * @return array{
     *     0: list<array{class: string, method: string, node: ?Node, validateCount: bool, external: bool}>,
     *     1: bool,
     *     2: list<array{class: ?string, method: ?string, span: Span, external: bool}>,
     * }
     */
    private function extractMethodAnnotations(
        NodeAnalysisContext $context,
        SourceFile $source,
        Node $method,
        string $className,
        bool $supportsClosure,
        bool $supportsDocblock,
    ): array {
        $providers = [];
        $hasTestWith = false;
        $dependencies = [];

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
                if ($normalized === self::TEST_WITH || $normalized === self::TEST_WITH_JSON) {
                    $hasTestWith = true;
                    continue;
                }

                if (isset(self::METHOD_DEPENDENCY_ATTRIBUTES[$normalized])) {
                    $arguments = $this->attributeArguments($source, $attribute);
                    if (isset(self::SAME_CLASS_DEPENDENCY_ATTRIBUTES[$normalized])) {
                        $depMethod = $this->literalString(
                            $source,
                            $this->attributeArgument($source, $arguments, 0, 'methodName'),
                        );
                        $dependencies[] = [
                            'class' => $depMethod !== null && $depMethod !== '' ? $className : null,
                            'method' => $depMethod !== null && $depMethod !== '' ? $depMethod : null,
                            'span' => $attribute->span,
                            'external' => false,
                        ];
                        continue;
                    }

                    $classArg = $this->attributeArgument($source, $arguments, 0, 'className');
                    $methodArg = $this->attributeArgument($source, $arguments, 1, 'methodName');
                    $depClass = $classArg !== null ? $this->referencedClass($context, $source, $classArg) : null;
                    $depMethod = $this->literalString($source, $methodArg);
                    $dependencies[] = [
                        'class' => $depClass,
                        'method' => $depMethod !== null && $depMethod !== '' ? $depMethod : null,
                        'span' => $attribute->span,
                        'external' => true,
                    ];
                    continue;
                }

                if ($normalized === self::DATA_PROVIDER) {
                    $arguments = $this->attributeArguments($source, $attribute);
                    $provider = $this->literalString(
                        $source,
                        $this->attributeArgument($source, $arguments, 0, 'methodName'),
                    );
                    if ($provider === null || $provider === '') {
                        continue;
                    }

                    $providers[] = [
                        'class' => $className,
                        'method' => $provider,
                        'node' => null,
                        'validateCount' => $this->validateArgumentCount(
                            $source,
                            $this->attributeArgument($source, $arguments, 1, 'validateArgumentCount'),
                        ),
                        'external' => false,
                    ];
                    continue;
                }

                if ($normalized === self::DATA_PROVIDER_CLOSURE) {
                    if (!$supportsClosure) {
                        continue;
                    }

                    $arguments = $this->attributeArguments($source, $attribute);
                    $closure = $this->closureArgument(
                        $source,
                        $this->attributeArgument($source, $arguments, 0, 'closure'),
                    );
                    if ($closure === null) {
                        continue;
                    }

                    $providers[] = [
                        'class' => '',
                        'method' => '',
                        'node' => $closure,
                        'validateCount' => $this->validateArgumentCount(
                            $source,
                            $this->attributeArgument($source, $arguments, 1, 'validateArgumentCount'),
                        ),
                        'external' => false,
                    ];
                    continue;
                }

                if ($normalized === self::DATA_PROVIDER_EXTERNAL) {
                    $arguments = $this->attributeArguments($source, $attribute);
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

                    $providers[] = [
                        'class' => $providerClass,
                        'method' => $provider,
                        'node' => null,
                        'validateCount' => $this->validateArgumentCount(
                            $source,
                            $this->attributeArgument($source, $arguments, 2, 'validateArgumentCount'),
                        ),
                        'external' => true,
                    ];
                }
            }
        }

        if ($supportsDocblock) {
            $docblock = $this->attachedDocblock($source, $method);
            if ($docblock !== null && stripos($docblock, '@dataProvider') !== false) {
                $matched = preg_match_all(
                    '/^[ \t]*\*?[ \t]*@dataProvider[ \t]+(\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*)(?:::([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*))?/m',
                    $docblock,
                    $matches,
                    PREG_SET_ORDER,
                );
                if ($matched !== false) {
                    foreach ($matches as $match) {
                        $resolved = $this->docblockProvider($context, $className, $match[1], $match[2] ?? '');
                        if ($resolved === null) {
                            continue;
                        }

                        $providers[] = [
                            'class' => $resolved['class'],
                            'method' => $resolved['method'],
                            'node' => null,
                            'validateCount' => true,
                            'external' => false,
                        ];
                    }
                }
            }
        }

        return [$providers, $hasTestWith, $dependencies];
    }

    /**
     * @param list<AttributeMetadata> $attributes
     * @return list<array{class: ?string, method: ?string, span: Span, external: bool}>
     */
    private function dependencyReferencesFromAttributes(array $attributes, string $className): array
    {
        $dependencies = [];
        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->name);
            if (!isset(self::METHOD_DEPENDENCY_ATTRIBUTES[$name])) {
                continue;
            }

            if (isset(self::SAME_CLASS_DEPENDENCY_ATTRIBUTES[$name])) {
                $depMethod = $attribute->getArgument(0, 'methodName')?->valueType?->getLiteralString();
                $dependencies[] = [
                    'class' => $depMethod !== null && $depMethod !== '' ? $className : null,
                    'method' => $depMethod !== null && $depMethod !== '' ? $depMethod : null,
                    'span' => $attribute->location->span,
                    'external' => false,
                ];
                continue;
            }

            $classArg = $attribute->getArgument(0, 'className');
            $depClass = $classArg?->valueType?->getLiteralClassString() ?? $classArg?->valueType?->getLiteralString();
            $depMethod = $attribute->getArgument(1, 'methodName')?->valueType?->getLiteralString();
            $dependencies[] = [
                'class' => $depClass !== null && $depClass !== '' ? $depClass : null,
                'method' => $depMethod !== null && $depMethod !== '' ? $depMethod : null,
                'span' => $attribute->location->span,
                'external' => true,
            ];
        }

        return $dependencies;
    }

    /**
     * Filters out dependencies whose declared return type is `void` or `never` when the installed
     * PHPUnit skips non-value-returning producers (PassedTests::hasReturnValue()).
     *
     * @param list<array{class: ?string, method: ?string, span: Span, external: bool}> $dependencies
     * @return list<array{class: ?string, method: ?string, span: Span, external: bool}>
     */
    private function valuePassingDependencies(LifecycleContext $context, array $dependencies): array
    {
        if ($dependencies === []) {
            return [];
        }

        // PHPUnit keys dependency inputs by producer target, so repeated #[Depends]
        // attributes targeting the same producer occupy a single parameter slot.
        $deduped = [];
        $seen = [];
        foreach ($dependencies as $dependency) {
            if ($dependency['class'] !== null && $dependency['method'] !== null) {
                $key = strtolower($dependency['class'] . '::' . $dependency['method']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            $deduped[] = $dependency;
        }

        if (!PhpUnitVersion::skipsVoidDependencyReturnValue($context->codebase)) {
            return $deduped;
        }

        $passing = [];
        foreach ($deduped as $dependency) {
            if ($dependency['class'] !== null && $dependency['method'] !== null) {
                $producer = $context->codebase->getMethod($dependency['class'], $dependency['method']);
                $declared = $producer?->declaredReturnType?->type;
                if (
                    $declared !== null
                    && count($declared->atomicTypes) === 1
                    && $declared->atomicTypes[0] instanceof SimpleAtomicType
                    && (
                        $declared->atomicTypes[0]->kind === SimpleAtomicTypeKind::Void
                        || $declared->atomicTypes[0]->kind === SimpleAtomicTypeKind::Never
                    )
                ) {
                    continue;
                }
            }
            $passing[] = $dependency;
        }

        return $passing;
    }

    /**
     * @param list<array{class: ?string, method: ?string, span: Span, external: bool}> $dependencies
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
     * @param array<int, true> $checkedOffsets
     */
    private function checkDependencyTypes(
        LifecycleContext $context,
        string $testFile,
        array $dependencies,
        int $offset,
        string $testLabel,
        array $schema,
        array &$checkedOffsets,
    ): void {
        if ($dependencies === [] || isset($checkedOffsets[$offset])) {
            return;
        }
        $checkedOffsets[$offset] = true;

        foreach ($dependencies as $index => $dependency) {
            $paramIndex = $offset + $index;
            $parameter = $schema['positional'][$paramIndex] ?? $schema['variadicParameter'];
            if ($parameter === null || $parameter['type'] === null) {
                continue;
            }

            if ($dependency['class'] === null || $dependency['method'] === null) {
                continue;
            }

            $producer = $context->codebase->getMethod($dependency['class'], $dependency['method']);
            if ($producer === null) {
                continue;
            }

            $returnType = $producer->returnType?->type ?? $producer->declaredReturnType?->type;
            if ($returnType === null) {
                continue;
            }

            if (
                count($returnType->atomicTypes) === 1
                && $returnType->atomicTypes[0] instanceof SimpleAtomicType
                && $returnType->atomicTypes[0]->kind === SimpleAtomicTypeKind::Void
            ) {
                $returnType = Type::null();
            }

            if (!$this->isCheckableType($returnType)) {
                continue;
            }
            if ($this->isFastPathContained($returnType, $parameter['type'])) {
                continue;
            }
            if ($context->types->isContainedBy($returnType, $parameter['type'])) {
                continue;
            }

            $depLabel = $dependency['external']
                ? $dependency['class'] . '::' . $dependency['method'] . '()'
                : $dependency['method'] . '()';

            $this->report($context, $testFile, $dependency['span'], sprintf(
                'Dependency %s provides %s for $%s of %s(), which expects %s.',
                $depLabel,
                (string) $returnType,
                $parameter['name'],
                $testLabel,
                (string) $parameter['type'],
            ));
        }
    }

    private function classNodeName(SourceFile $source, Node $classNode): ?string
    {
        foreach ($source->getChildren($classNode) as $child) {
            if ($child->kind !== NodeKind::LocalIdentifier) {
                continue;
            }

            return $source->getResolvedName($child)?->name;
        }

        return null;
    }

    /**
     * @return array<string, array{label: string, node: Node}>
     */
    private function methodsInClass(SourceFile $source, Node $classNode): array
    {
        $methods = [];
        foreach ($source->getChildren($classNode) as $member) {
            $method = $member->kind === NodeKind::Method
                ? $member
                : ($member->kind === NodeKind::ClassLikeMember ? ($source->getChildren($member)[0] ?? null) : null);
            if ($method === null || $method->kind !== NodeKind::Method) {
                continue;
            }

            $label = $this->methodLabel($source, $method);
            $methods[strtolower($label)] = ['label' => $label, 'node' => $method];
        }

        return $methods;
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

            foreach ($source->getChildren($classNode) as $member) {
                $method = $member->kind === NodeKind::Method
                    ? $member
                    : ($member->kind === NodeKind::ClassLikeMember ? ($source->getChildren($member)[0] ?? null) : null);
                if ($method === null || $method->kind !== NodeKind::Method) {
                    continue;
                }

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
     * @param list<array{class: ?string, method: ?string, span: Span, external: bool}> $dependencies
     * @param array<int, true> $checkedDepOffsets
     */
    private function checkProvider(
        LifecycleContext $context,
        FileAnalysis $analysis,
        string $testFile,
        SourceFile $source,
        Node $provider,
        string $test,
        string $sourceLabel,
        array $schema,
        bool $validateCount,
        array $dependencies,
        array &$checkedDepOffsets,
        bool $needsKeyOrder = false,
    ): void {
        foreach ($this->extractProviderRows($analysis, $source, $provider, $needsKeyOrder) as $row) {
            $this->checkRow(
                $context,
                $analysis->file,
                $testFile,
                $row['type'],
                $row['span'],
                $row['label'],
                $test,
                $sourceLabel,
                $schema,
                $validateCount,
                $dependencies,
                $checkedDepOffsets,
                $row['keyOrder'],
            );
        }
    }

    /**
     * @return list<array{type: Type, span: Span, label: string, keyOrder: ?list<string>}>
     */
    private function extractProviderRows(FileAnalysis $analysis, SourceFile $source, Node $provider, bool $needsKeyOrder = false): array
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

                    foreach ($this->rowsFromCollection($source, $collection, $type, $needsKeyOrder) as $row) {
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
                    'keyOrder' => $needsKeyOrder ? $this->literalStringKeyOrder($source, $expression) : null,
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

            foreach ($this->rowsFromCollection($source, $expression, $type, $needsKeyOrder) as $row) {
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
     * @param list<array{class: ?string, method: ?string, span: Span, external: bool}> $dependencies
     * @param array<int, true> $checkedDepOffsets
     */
    private function checkTestWithAttributes(
        LifecycleContext $context,
        string $reportFile,
        SourceFile $source,
        Node $methodNode,
        array $attributes,
        string $test,
        array $schema,
        array $dependencies,
        array &$checkedDepOffsets,
        bool $needsKeyOrder = false,
    ): void {
        $testWithDataNodes = $needsKeyOrder ? $this->testWithDataArgumentNodes($source, $methodNode) : [];
        $index = 0;
        $testWithIndex = 0;
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
                $dataNode = $testWithDataNodes[$testWithIndex] ?? null;
                $testWithIndex++;
                $dataArg = $attribute->getArgument(0, 'data');
                $type = $dataArg?->valueType;
                if ($type === null) {
                    continue;
                }

                $span = $dataArg->valueLocation?->span ?? $attribute->location->span;
                $keyOrder = ($needsKeyOrder && $dataNode !== null) ? $this->literalStringKeyOrder($source, $dataNode) : null;
                $this->checkRow(
                    $context,
                    $reportFile,
                    $reportFile,
                    $type,
                    $span,
                    $label,
                    $test,
                    'Attribute #[TestWith]',
                    $schema,
                    true,
                    $dependencies,
                    $checkedDepOffsets,
                    $keyOrder,
                );
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

            $jsonKeyOrder = null;
            if ($decoded !== [] && !array_is_list($decoded)) {
                $keys = [];
                $allStrings = true;
                foreach (array_keys($decoded) as $k) {
                    if (!is_string($k)) {
                        $allStrings = false;
                        break;
                    }
                    $keys[] = $k;
                }
                if ($allStrings) {
                    $jsonKeyOrder = $keys;
                }
            }

            $this->checkRow(
                $context,
                $reportFile,
                $reportFile,
                self::jsonValueToType($decoded),
                $span,
                $label,
                $test,
                'Attribute #[TestWithJson]',
                $schema,
                true,
                $dependencies,
                $checkedDepOffsets,
                $jsonKeyOrder,
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
     * @return list<array{type: Type, span: Span, label: string, keyOrder: ?list<string>}>
     */
    private function rowsFromCollection(SourceFile $source, Node $expression, Type $type, bool $needsKeyOrder = false): array
    {
        if (count($type->atomicTypes) !== 1) {
            return [];
        }

        $atomic = $type->atomicTypes[0];
        if ($atomic instanceof ListType) {
            $elementNodes = $needsKeyOrder ? $this->outerArrayElements($source, $expression) : null;
            $rows = [];
            foreach ($atomic->knownElements ?? [] as $element) {
                if ($element->optional) {
                    continue;
                }

                $elementNode = $elementNodes['list'][$element->index] ?? null;
                $rows[] = [
                    'type' => $element->type,
                    'span' => $elementNode?->span ?? $expression->span,
                    'label' => '#' . $element->index,
                    'keyOrder' => ($needsKeyOrder && $elementNode !== null) ? $this->literalStringKeyOrder($source, $elementNode) : null,
                ];
            }

            return $rows;
        }

        if (!$atomic instanceof KeyedArrayType || $atomic->knownItems === null) {
            return [];
        }

        $elementNodes = $needsKeyOrder ? $this->outerArrayElements($source, $expression) : ['list' => [], 'int' => [], 'string' => []];
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

            $elementNode = null;
            if ($needsKeyOrder) {
                $elementNode = match ($item->key->kind) {
                    ArrayKeyKind::String => is_string($item->key->value) ? ($elementNodes['string'][$item->key->value] ?? null) : null,
                    ArrayKeyKind::Integer => is_int($item->key->value) ? ($elementNodes['int'][$item->key->value] ?? null) : null,
                    ArrayKeyKind::ClassLikeConstant => null,
                };
            }

            $rows[] = [
                'type' => $item->type,
                'span' => $expression->span,
                'label' => $label,
                'keyOrder' => $elementNode !== null ? $this->literalStringKeyOrder($source, $elementNode) : null,
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
     * @param list<array{class: ?string, method: ?string, span: Span, external: bool}> $dependencies
     * @param array<int, true> $checkedDepOffsets
     * @param list<string>|null $keyOrder
     */
    private function checkRow(
        LifecycleContext $context,
        string $reportFile,
        string $testFile,
        Type $type,
        Span $span,
        string $label,
        string $test,
        string $sourceLabel,
        array $schema,
        bool $validateCount,
        array $dependencies,
        array &$checkedDepOffsets,
        ?array $keyOrder = null,
    ): void {
        $named = $this->namedArguments($type);
        if ($named !== null) {
            if (!PhpUnitVersion::supportsNamedProviderArguments($context->codebase)) {
                $ordered = null;
                if (count($named) <= 1) {
                    $ordered = array_values($named);
                } elseif ($keyOrder !== null && count($keyOrder) === count($named)) {
                    $resolved = [];
                    $allMatched = true;
                    foreach ($keyOrder as $key) {
                        if (!array_key_exists($key, $named)) {
                            $allMatched = false;
                            break;
                        }
                        $resolved[] = $named[$key];
                    }
                    if ($allMatched) {
                        $ordered = $resolved;
                    }
                }

                if ($ordered !== null) {
                    $this->checkPositionalArguments(
                        $context,
                        $reportFile,
                        $testFile,
                        $ordered,
                        $span,
                        $label,
                        $test,
                        $sourceLabel,
                        $schema,
                        $validateCount,
                        $dependencies,
                        $checkedDepOffsets,
                    );

                    return;
                }

                // Non-literal multi-key array: Mago sorts KeyedArrayType keys alphabetically, so
                // insertion order for PHPUnit 10.5 array_values() is unknown; check arity only.
                $this->checkPositionalArity(
                    $context,
                    $reportFile,
                    count($named),
                    $span,
                    $label,
                    $test,
                    $sourceLabel,
                    $schema,
                    $validateCount,
                );
                if (count($named) >= $schema['required']) {
                    $this->checkDependencyTypes($context, $testFile, $dependencies, count($named), $test, $schema, $checkedDepOffsets);
                }

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

        $this->checkPositionalArguments(
            $context,
            $reportFile,
            $testFile,
            $arguments,
            $span,
            $label,
            $test,
            $sourceLabel,
            $schema,
            $validateCount,
            $dependencies,
            $checkedDepOffsets,
        );
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
     * @param list<array{class: ?string, method: ?string, span: Span, external: bool}> $dependencies
     * @param array<int, true> $checkedDepOffsets
     */
    private function checkPositionalArguments(
        LifecycleContext $context,
        string $reportFile,
        string $testFile,
        array $arguments,
        Span $span,
        string $label,
        string $test,
        string $sourceLabel,
        array $schema,
        bool $validateCount,
        array $dependencies,
        array &$checkedDepOffsets,
    ): void {
        $this->checkPositionalArity(
            $context,
            $reportFile,
            count($arguments),
            $span,
            $label,
            $test,
            $sourceLabel,
            $schema,
            $validateCount,
        );

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

        if (count($arguments) >= $schema['required']) {
            $this->checkDependencyTypes($context, $testFile, $dependencies, count($arguments), $test, $schema, $checkedDepOffsets);
        }
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
    private function checkPositionalArity(
        LifecycleContext $context,
        string $reportFile,
        int $count,
        Span $span,
        string $label,
        string $test,
        string $sourceLabel,
        array $schema,
        bool $validateCount,
    ): void {
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
        if ($this->isFastPathContained($argumentType, $parameterType)) {
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

    private function isFastPathContained(Type $argumentType, Type $parameterType): bool
    {
        foreach ($parameterType->atomicTypes as $expected) {
            if ($expected instanceof MixedType) {
                return true;
            }
        }

        foreach ($argumentType->atomicTypes as $actual) {
            $matched = false;
            foreach ($parameterType->atomicTypes as $expected) {
                if (self::isAtomicFastPathContained($actual, $expected)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private static function isAtomicFastPathContained(object $actual, object $expected): bool
    {
        if ($actual instanceof SimpleAtomicType && $expected instanceof SimpleAtomicType) {
            return $actual->kind === $expected->kind;
        }

        if ($actual instanceof NamedObjectType) {
            if ($expected instanceof AnyObjectType) {
                return true;
            }
            if (
                $expected instanceof NamedObjectType
                && $expected->parameters === null
                && $expected->intersections === null
                && strtolower($actual->name) === strtolower($expected->name)
            ) {
                return true;
            }
        }

        if ($actual instanceof ScalarType && $expected instanceof ScalarType) {
            if (!self::isUnconstrainedScalar($expected)) {
                return false;
            }
            if ($actual->kind === $expected->kind) {
                return true;
            }
            if ($expected->kind === ScalarTypeKind::String && $actual->kind === ScalarTypeKind::ClassLikeString) {
                return true;
            }
            if ($expected->kind === ScalarTypeKind::Float && $actual->kind === ScalarTypeKind::Integer) {
                return true;
            }
            if (
                $expected->kind === ScalarTypeKind::ArrayKey
                && ($actual->kind === ScalarTypeKind::Integer || $actual->kind === ScalarTypeKind::String || $actual->kind === ScalarTypeKind::ClassLikeString)
            ) {
                return true;
            }
            if ($expected->kind === ScalarTypeKind::Scalar) {
                return true;
            }
        }

        if ($actual instanceof ListType || $actual instanceof KeyedArrayType) {
            if (
                $expected instanceof KeyedArrayType
                && $expected->knownItems === null
                && !$expected->nonEmpty
                && ($expected->valueType === null || self::isMixedType($expected->valueType))
                && ($expected->keyType === null || self::isMixedOrArrayKeyType($expected->keyType))
            ) {
                return true;
            }
            if (
                $expected instanceof IterableType
                && $expected->intersections === null
                && self::isMixedType($expected->valueType)
                && self::isMixedOrArrayKeyType($expected->keyType)
            ) {
                return true;
            }
            if (
                $actual instanceof ListType
                && $expected instanceof ListType
                && $expected->knownElements === null
                && $expected->knownCount === null
                && !$expected->nonEmpty
                && self::isMixedType($expected->elementType)
            ) {
                return true;
            }
        }

        return false;
    }

    private static function isUnconstrainedScalar(ScalarType $type): bool
    {
        $ref = $type->refinement;
        if ($ref === null) {
            return true;
        }
        if ($ref instanceof StringType) {
            return $ref->literalKind === StringLiteralKind::General
                && !$ref->numeric
                && !$ref->truthy
                && !$ref->nonEmpty
                && !$ref->callable
                && $ref->casing === StringCasing::Unspecified;
        }
        if ($ref instanceof IntegerType) {
            return $ref->kind === IntegerTypeKind::General;
        }
        if ($ref instanceof FloatType) {
            return $ref->kind === FloatTypeKind::General;
        }

        return false;
    }

    private static function isMixedType(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType) {
                return true;
            }
        }

        return false;
    }

    private static function isMixedOrArrayKeyType(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType) {
                return true;
            }
            if ($atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::ArrayKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parses a test method's native PHP parameter schema directly from the AST when no @param
     * docblock or complex hint is present, avoiding a Codebase::getMultipleMethods() IPC roundtrip.
     *
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
     * }|null
     */
    private function astParameterSchema(SourceFile $source, Node $method, int $dependencies): ?array
    {
        $paramList = null;
        foreach ($source->getChildren($method) as $child) {
            if ($child->kind === NodeKind::FunctionLikeParameterList) {
                $paramList = $child;
                break;
            }
        }
        if ($paramList === null) {
            return null;
        }

        $required = 0;
        $accepted = 0;
        $variadic = false;
        $names = [];
        $requiredNames = [];
        $positional = [];
        $named = [];
        $variadicParameter = null;

        foreach ($source->getChildren($paramList) as $param) {
            if ($param->kind !== NodeKind::FunctionLikeParameter) {
                continue;
            }

            $varNode = null;
            $hintNode = null;
            $defaultNode = null;
            foreach ($source->getChildren($param) as $part) {
                if ($part->kind === NodeKind::DirectVariable) {
                    $varNode = $part;
                } elseif ($part->kind === NodeKind::Hint) {
                    $hintNode = $part;
                } elseif ($part->kind === NodeKind::FunctionLikeParameterDefaultValue) {
                    $defaultNode = $part;
                }
            }
            $hasDefault = $defaultNode !== null;
            if ($varNode === null) {
                return null;
            }
            if ($defaultNode !== null && $hintNode !== null) {
                $defaultText = strtolower(trim(ltrim(trim($source->getText($defaultNode)), '=')));
                if ($defaultText === 'null') {
                    // Fall back to Mago metadata so PHP's implicitly nullable parameters
                    // (e.g. `string $v = null`) retain their exact nullability.
                    return null;
                }
            }

            $name = ltrim($source->getText($varNode), '$');
            $prefix = $source->getText(new Span($param->span->start, $varNode->span->start));
            $isVariadic = str_contains($prefix, '...');

            $type = null;
            if ($hintNode !== null) {
                $parsed = $this->astHintToType($source, $hintNode);
                if (!$parsed['ok']) {
                    return null;
                }
                $type = $parsed['type'];
                if ($type !== null && !$this->isCheckableType($type)) {
                    $type = null;
                }
            }

            if ($isVariadic) {
                $variadic = true;
                $variadicParameter = ['name' => $name, 'type' => $type];
                continue;
            }

            $names[] = $name;
            $positional[] = ['name' => $name, 'type' => $type];
            $named[$name] = $type;
            $accepted++;
            if (!$hasDefault) {
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

    /**
     * @return array{ok: bool, type: ?Type}
     */
    private function astHintToType(SourceFile $source, Node $node): array
    {
        $current = $node->kind === NodeKind::Hint ? ($source->getChildren($node)[0] ?? null) : $node;
        if ($current === null) {
            return ['ok' => false, 'type' => null];
        }

        if ($current->kind === NodeKind::NullableHint) {
            $innerNode = $source->getChildren($current)[0] ?? null;
            if ($innerNode === null) {
                return ['ok' => false, 'type' => null];
            }
            $inner = $this->astHintToType($source, $innerNode);
            if (!$inner['ok'] || $inner['type'] === null) {
                return ['ok' => false, 'type' => null];
            }

            return ['ok' => true, 'type' => Type::union($inner['type'], Type::null())];
        }

        if ($current->kind === NodeKind::UnionHint) {
            $members = [];
            foreach ($source->getChildren($current) as $child) {
                $parsed = $this->astHintToType($source, $child);
                if (!$parsed['ok'] || $parsed['type'] === null) {
                    return ['ok' => false, 'type' => null];
                }
                $members[] = $parsed['type'];
            }
            if ($members === []) {
                return ['ok' => false, 'type' => null];
            }

            return ['ok' => true, 'type' => Type::union(...$members)];
        }

        if ($current->kind === NodeKind::IntersectionHint || $current->kind === NodeKind::ParenthesizedHint) {
            return ['ok' => false, 'type' => null];
        }

        $text = strtolower(trim($source->getText($current)));
        return match ($text) {
            'int' => ['ok' => true, 'type' => Type::int()],
            'float' => ['ok' => true, 'type' => Type::float()],
            'string' => ['ok' => true, 'type' => Type::string()],
            'bool' => ['ok' => true, 'type' => Type::bool()],
            'true' => ['ok' => true, 'type' => Type::true()],
            'false' => ['ok' => true, 'type' => Type::false()],
            'null' => ['ok' => true, 'type' => Type::null()],
            'array' => ['ok' => true, 'type' => Type::array(Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed())],
            'iterable' => ['ok' => true, 'type' => Type::fromAtomic(new IterableType(Type::mixed(), Type::mixed(), null))],
            'object' => ['ok' => true, 'type' => Type::object()],
            'mixed' => ['ok' => true, 'type' => null],
            default => ['ok' => false, 'type' => null],
        };
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

    /**
     * @return array{list: list<Node>, int: array<int, Node>, string: array<string, Node>}
     */
    private function outerArrayElements(SourceFile $source, Node $expression): array
    {
        $empty = ['list' => [], 'int' => [], 'string' => []];
        $array = $this->unwrapArrayNode($source, $expression);
        if ($array === null) {
            return $empty;
        }

        $list = [];
        $byInt = [];
        $byString = [];
        $nextInt = 0;
        foreach ($source->getChildren($array) as $rawChild) {
            $child = $rawChild->kind === NodeKind::ArrayElement
                ? ($source->getChildren($rawChild)[0] ?? null)
                : $rawChild;
            if ($child === null) {
                continue;
            }
            if ($child->kind === NodeKind::VariadicArrayElement || $child->kind === NodeKind::MissingArrayElement) {
                return $empty;
            }
            if ($child->kind === NodeKind::ValueArrayElement) {
                $list[] = $child;
                $byInt[$nextInt] = $child;
                $nextInt++;
                continue;
            }
            if ($child->kind === NodeKind::KeyValueArrayElement) {
                $list[] = $child;
                $children = $source->getChildren($child);
                $keyNode = $children[0] ?? null;
                $strKey = $this->directLiteralString($source, $keyNode);
                if ($strKey !== null) {
                    $byString[$strKey] = $child;
                    continue;
                }
                $intKey = $this->directLiteralInt($source, $keyNode);
                if ($intKey !== null) {
                    $byInt[$intKey] = $child;
                    if ($intKey >= $nextInt) {
                        $nextInt = $intKey + 1;
                    }
                }
            }
        }

        return ['list' => $list, 'int' => $byInt, 'string' => $byString];
    }

    /**
     * Reconstructs PHP insertion order of literal string keys from an array AST node.
     *
     * @return list<string>|null
     */
    private function literalStringKeyOrder(SourceFile $source, Node $expression): ?array
    {
        $array = $this->unwrapArrayNode($source, $expression);
        if ($array === null) {
            return null;
        }

        $order = [];
        $seen = [];
        foreach ($source->getChildren($array) as $rawChild) {
            $child = $rawChild->kind === NodeKind::ArrayElement
                ? ($source->getChildren($rawChild)[0] ?? null)
                : $rawChild;
            if ($child === null) {
                continue;
            }
            if (
                $child->kind === NodeKind::VariadicArrayElement
                || $child->kind === NodeKind::MissingArrayElement
                || $child->kind === NodeKind::ValueArrayElement
            ) {
                return null;
            }
            if ($child->kind !== NodeKind::KeyValueArrayElement) {
                continue;
            }

            $keyNode = $source->getChildren($child)[0] ?? null;
            $key = $this->directLiteralString($source, $keyNode);
            if ($key === null) {
                return null;
            }
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $order[] = $key;
            }
        }

        return $order === [] ? null : $order;
    }

    private function unwrapArrayNode(SourceFile $source, Node $node): ?Node
    {
        $current = $node;
        while (true) {
            if ($current->kind === NodeKind::Array || $current->kind === NodeKind::LegacyArray) {
                return $current;
            }

            $children = $source->getChildren($current);
            if ($current->kind === NodeKind::KeyValueArrayElement || $current->kind === NodeKind::NamedArgument) {
                $next = $children !== [] ? $children[array_key_last($children)] : null;
            } elseif (
                $current->kind === NodeKind::ArrayElement
                || $current->kind === NodeKind::ValueArrayElement
                || $current->kind === NodeKind::PositionalArgument
                || $current->kind === NodeKind::Expression
                || $current->kind === NodeKind::Parenthesized
            ) {
                $next = $children[0] ?? null;
            } else {
                return null;
            }

            if ($next === null) {
                return null;
            }
            $current = $next;
        }
    }

    private function directLiteralString(SourceFile $source, ?Node $node): ?string
    {
        $current = $node;
        while (
            $current !== null
            && (
                $current->kind === NodeKind::Expression
                || $current->kind === NodeKind::Parenthesized
                || $current->kind === NodeKind::Literal
            )
        ) {
            $current = $source->getChildren($current)[0] ?? null;
        }
        if ($current === null || $current->kind !== NodeKind::LiteralString) {
            return null;
        }

        return $source->getLiteralString($current) ?? $this->decodeQuoted($source->getText($current));
    }

    private function directLiteralInt(SourceFile $source, ?Node $node): ?int
    {
        $current = $node;
        while (
            $current !== null
            && (
                $current->kind === NodeKind::Expression
                || $current->kind === NodeKind::Parenthesized
                || $current->kind === NodeKind::Literal
            )
        ) {
            $current = $source->getChildren($current)[0] ?? null;
        }
        if ($current === null || $current->kind !== NodeKind::LiteralInteger) {
            return null;
        }

        $raw = str_replace('_', '', $source->getText($current));
        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    /** @return list<?Node> */
    private function testWithDataArgumentNodes(SourceFile $source, Node $method): array
    {
        $nodes = [];
        foreach ($source->getChildren($method) as $list) {
            if ($list->kind !== NodeKind::AttributeList) {
                continue;
            }
            foreach ($source->getChildren($list) as $attribute) {
                if ($attribute->kind !== NodeKind::Attribute) {
                    continue;
                }
                if (strtolower($this->attributeName($source, $attribute) ?? '') !== self::TEST_WITH) {
                    continue;
                }
                $arguments = $this->attributeArguments($source, $attribute);
                $nodes[] = $this->attributeArgument($source, $arguments, 0, 'data');
            }
        }

        return $nodes;
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
