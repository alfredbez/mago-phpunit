<?php

declare(strict_types=1);

chdir(dirname(__DIR__));
require __DIR__ . '/../vendor/autoload.php';

$failures = 0;

$magoBinEnv = getenv('MAGO_BIN');
$magoCommand = ($magoBinEnv !== false && $magoBinEnv !== '')
    ? [$magoBinEnv]
    : [PHP_BINARY, 'vendor/bin/mago'];

$version = trim(run([...$magoCommand, '--version'])['stdout']);
$phpunit = trim(run([PHP_BINARY, 'vendor/bin/phpunit', '--version'])['stdout']);
$phpunitMajor = 0;
if (preg_match('/PHPUnit (\d+)/', $phpunit, $phpunitMatch) === 1) {
    $phpunitMajor = (int) $phpunitMatch[1];
}
fwrite(STDOUT, $version . "\n" . $phpunit . "\n\n");
if (preg_match('/^mago (\d+\.\d+\.\d+)/', $version, $versionMatch) !== 1 || version_compare($versionMatch[1], '1.60.2', '<')) {
    fwrite(STDERR, "The fixture expectations require Mago 1.60.2 or newer\n");
    exit(1);
}

$validated = run([...$magoCommand, 'extension', 'validate']);
fwrite(STDOUT, "extension validate (exit {$validated['exit']}):\n");
fwrite(STDOUT, trim($validated['stdout'] . $validated['stderr']) . "\n\n");
if ($validated['exit'] !== 0) {
    fwrite(STDERR, "extension validate failed\n");
    exit(1);
}

$with = analyze(false);
$without = analyze(true);

fwrite(STDOUT, "with extension (" . count($with) . " issues):\n");
printIssues($with);
fwrite(STDOUT, "\n--no-extensions (" . count($without) . " issues):\n");
printIssues($without);
fwrite(STDOUT, "\n");

$expected = [
    false => [
        'tests/fixtures/HasKeyPositive.php' => [
            'possibly-undefined-string-array-index' => 0,
            'possibly-undefined-int-array-index' => 0,
            'nullable-return-statement' => 0,
            'invalid-return-statement' => 0,
        ],
        'tests/fixtures/HasKeyCounter.php' => [
            'possibly-undefined-string-array-index' => 2,
            'possibly-null-operand' => 1,
            'nullable-return-statement' => 1,
            'invalid-return-statement' => 1,
        ],
        'tests/fixtures/HasKeyFunction.php' => [
            'possibly-undefined-string-array-index' => 4,
            'nullable-return-statement' => 1,
            'invalid-return-statement' => 1,
            'named-argument-not-allowed' => 2,
        ],
        'tests/fixtures/HasKeyNumeric.php' => [
            'possibly-undefined-int-array-index' => 2,
            'possibly-undefined-string-array-index' => 1,
        ],
        'tests/fixtures/HasKeyNullable.php' => [
            'possibly-undefined-string-array-index' => 2,
            'nullable-return-statement' => 4,
            'invalid-return-statement' => 4,
        ],
        'tests/fixtures/SetUpPositive.php' => [
            'uninitialized-property' => 0,
        ],
        'tests/fixtures/BeforeInitializer.php' => [
            'uninitialized-property' => 4,
        ],
        'tests/fixtures/SetUpCounter.php' => [
            'uninitialized-property' => 1,
        ],
        'tests/fixtures/SetUpInherited.php' => [
            'uninitialized-property' => 0,
        ],
        'tests/fixtures/SetUpHelper.php' => [
            'uninitialized-property' => 0,
        ],
        'tests/fixtures/SetUpNotTestCase.php' => [
            'missing-constructor' => 1,
        ],
    ],
    true => [
        'tests/fixtures/HasKeyPositive.php' => [
            'possibly-undefined-string-array-index' => 4,
            'possibly-undefined-int-array-index' => 1,
            'nullable-return-statement' => 5,
            'invalid-return-statement' => 5,
        ],
        'tests/fixtures/HasKeyCounter.php' => [
            'possibly-undefined-string-array-index' => 3,
            'possibly-null-operand' => 2,
            'nullable-return-statement' => 1,
            'invalid-return-statement' => 1,
        ],
        'tests/fixtures/HasKeyFunction.php' => [
            'possibly-undefined-string-array-index' => 7,
            'possibly-undefined-int-array-index' => 1,
            'nullable-return-statement' => 1,
            'invalid-return-statement' => 1,
            'named-argument-not-allowed' => 2,
        ],
        'tests/fixtures/HasKeyNumeric.php' => [
            'possibly-undefined-int-array-index' => 6,
            'possibly-undefined-string-array-index' => 2,
            'nullable-return-statement' => 5,
            'invalid-return-statement' => 5,
        ],
        'tests/fixtures/HasKeyNullable.php' => [
            'possibly-undefined-string-array-index' => 7,
            'nullable-return-statement' => 4,
            'invalid-return-statement' => 4,
        ],
        'tests/fixtures/SetUpPositive.php' => [
            'uninitialized-property' => 1,
        ],
        'tests/fixtures/BeforeInitializer.php' => [
            'uninitialized-property' => 8,
        ],
        'tests/fixtures/SetUpCounter.php' => [
            'uninitialized-property' => 2,
        ],
        'tests/fixtures/SetUpInherited.php' => [
            'uninitialized-property' => 1,
        ],
        'tests/fixtures/SetUpHelper.php' => [
            'uninitialized-property' => 1,
        ],
        'tests/fixtures/SetUpNotTestCase.php' => [
            'missing-constructor' => 1,
        ],
    ],
];

// PHPUnit 11.5 and later narrow assertContainsOnlyInstancesOf() via @phpstan-assert, so the
// extension stays silent there and both runs match. PHPUnit 10.5 has no annotation, so only
// its --no-extensions run still reports the values as mixed.
$containsOnlyNarrowed = [
    'mixed-assignment' => 3,
    'mixed-method-access' => 1,
    'mixed-return-statement' => 2,
    'non-existent-method' => 1,
];
if ($phpunitMajor >= 11) {
    $containsOnlyNarrowed['possibly-invalid-argument'] = 1;
}
// PHPUnit 13 declares `@template T of object`, so a non-literal class name narrows to iterable<object>.
if ($phpunitMajor >= 13) {
    $containsOnlyNarrowed['mixed-assignment'] = 2;
    $containsOnlyNarrowed['mixed-method-access'] = 0;
    $containsOnlyNarrowed['ambiguous-object-method-access'] = 1;
}
$expected[false]['tests/fixtures/ContainsOnly.php'] = $containsOnlyNarrowed;
$expected[true]['tests/fixtures/ContainsOnly.php'] = $phpunitMajor >= 11
    ? $containsOnlyNarrowed
    : [
        'mixed-assignment' => 14,
        'mixed-method-access' => 7,
        'mixed-return-statement' => 7,
    ];
$expected[false]['tests/fixtures/AssertionsParity.php'] = [
    'undefined-string-array-index' => 2,
    'possibly-undefined-string-array-index' => 2,
    'invalid-return-statement' => 4,
];
$expected[true]['tests/fixtures/AssertionsParity.php'] = $phpunitMajor >= 11
    ? [
        'possibly-undefined-string-array-index' => 4,
        'invalid-return-statement' => 13,
        'nullable-return-statement' => 1,
    ]
    : [
        'possibly-undefined-string-array-index' => 4,
        'invalid-return-statement' => 10,
    ];
if ($phpunitMajor >= 13) {
    $containsOnlyRemoved = [
        'non-existent-method' => 3,
        'non-existent-function' => 1,
        'mixed-assignment' => 4,
        'mixed-method-access' => 1,
        'mixed-return-statement' => 4,
    ];
    $expected[false]['tests/fixtures/AssertContainsOnly.php'] = $containsOnlyRemoved;
    $expected[true]['tests/fixtures/AssertContainsOnly.php'] = $containsOnlyRemoved;
} else {
    $containsOnlyWith = [
        'mixed-assignment' => 1,
        'mixed-return-statement' => 1,
    ];
    $containsOnlyWithout = [
        'mixed-assignment' => 4,
        'mixed-method-access' => 1,
        'mixed-return-statement' => 4,
    ];
    if ($phpunitMajor >= 11) {
        $containsOnlyWith['deprecated-method'] = 3;
        $containsOnlyWith['deprecated-function'] = 1;
        $containsOnlyWith['invalid-argument'] = 1;
        $containsOnlyWith['possibly-invalid-argument'] = 1;
        $containsOnlyWithout['deprecated-method'] = 3;
        $containsOnlyWithout['deprecated-function'] = 1;
        $containsOnlyWithout['invalid-argument'] = 1;
        $containsOnlyWithout['possibly-invalid-argument'] = 1;
    }
    $expected[false]['tests/fixtures/AssertContainsOnly.php'] = $containsOnlyWith;
    $expected[true]['tests/fixtures/AssertContainsOnly.php'] = $containsOnlyWithout;
}
$hasKeyArrayAccess = [
    'invalid-return-statement' => 3,
    'non-existent-method' => 1,
    'nullable-return-statement' => 1,
];
$expected[false]['tests/fixtures/HasKeyArrayAccess.php'] = $hasKeyArrayAccess;
$expected[true]['tests/fixtures/HasKeyArrayAccess.php'] = $hasKeyArrayAccess;
$assertEqualsNoNarrowing = [
    'invalid-return-statement' => 5,
    'nullable-return-statement' => 1,
];
$expected[false]['tests/fixtures/AssertEqualsNoNarrowing.php'] = $assertEqualsNoNarrowing;
$expected[true]['tests/fixtures/AssertEqualsNoNarrowing.php'] = $assertEqualsNoNarrowing;
$expected[false]['tests/fixtures/DataProviderLifecycleOrder.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 1,
    'uninitialized-property' => 1,
];
$expected[true]['tests/fixtures/DataProviderLifecycleOrder.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
    'uninitialized-property' => 1,
];
// PHPUnit 10.5 declares @psalm-assert on assertInstanceOf(), assertNull(), and assertSame(),
// and PHPUnit 11.5 and later declare @phpstan-assert. Mago narrows all three without an extension.
$nativeAssertions = [
    'possibly-invalid-argument' => 1,
    'less-specific-return-statement' => 1,
    'nullable-return-statement' => 2,
    'invalid-return-statement' => 4,
];
$expected[false]['tests/fixtures/NativeAssertions.php'] = $nativeAssertions;
$expected[true]['tests/fixtures/NativeAssertions.php'] = $nativeAssertions;
// Mago 1.60.2 narrows createMock() and createStub() natively via PHPUnit's template annotations.
$nativeMocks = [
    'invalid-argument' => 1,
    'non-existent-method' => 2,
];
$expected[false]['tests/fixtures/NativeMocks.php'] = $nativeMocks;
$expected[true]['tests/fixtures/NativeMocks.php'] = $nativeMocks;
// PHPUnit 12 removed doc-comment metadata, so the @dataProvider fixture is only checked before 12.
$annotationsSupported = $phpunitMajor < 12;
// PHPUnit 11 introduced named argument unpacking for data provider rows; 10.5 strips keys with array_values().
$namedProviderArgs = $phpunitMajor >= 11;
$dataProviderIssues = ['alfredbez/phpunit/phpunit-data-provider' => $annotationsSupported ? 14 : 13];
// Up to PHPUnit 11.5 the DataProvider attribute takes only the method name. The
// validateArgumentCount argument is a PHPUnit 12 addition, so earlier versions report it.
if ($phpunitMajor < 12) {
    $dataProviderIssues['too-many-arguments'] = 1;
}
$expected[false]['tests/fixtures/DataProvider.php'] = $dataProviderIssues;
$expected[true]['tests/fixtures/DataProvider.php'] = $phpunitMajor < 12
    ? ['too-many-arguments' => 1]
    : ['alfredbez/phpunit/phpunit-data-provider' => 0];
$expected[false]['tests/fixtures/DataProviderDepends.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 3,
];
$expected[true]['tests/fixtures/DataProviderDepends.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[false]['tests/fixtures/DataProviderDependsNamed.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => $namedProviderArgs ? 4 : 2,
];
$expected[true]['tests/fixtures/DataProviderDependsNamed.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[false]['tests/fixtures/DataProviderVariadicNamed.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => $namedProviderArgs ? 3 : 5,
];
$expected[true]['tests/fixtures/DataProviderVariadicNamed.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[false]['tests/fixtures/DataProviderTypes.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => $namedProviderArgs ? 6 : 12,
];
$expected[true]['tests/fixtures/DataProviderTypes.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[false]['tests/fixtures/DataProviderYieldFrom.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 6,
];
$expected[true]['tests/fixtures/DataProviderYieldFrom.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[false]['tests/fixtures/DataProviderDependencyVariants.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 3,
];
$expected[true]['tests/fixtures/DataProviderDependencyVariants.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$skipsVoidDep = method_exists(\PHPUnit\TestRunner\TestResult\PassedTests::class, 'hasReturnValue');
$expected[false]['tests/fixtures/DependencyReturnTypes.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => $skipsVoidDep ? 9 : 10,
];
$expected[true]['tests/fixtures/DependencyReturnTypes.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[false]['tests/fixtures/DataProviderExternalCrossFile.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[true]['tests/fixtures/DataProviderExternalCrossFile.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[false]['tests/fixtures/ExternalCrossFileProvider.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 3,
];
$expected[true]['tests/fixtures/ExternalCrossFileProvider.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[false]['tests/fixtures/TestWithAttributes.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 24,
    'no-value' => 1,
    'non-existent-class-constant' => 2,
];
$expected[true]['tests/fixtures/TestWithAttributes.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
    'no-value' => 1,
    'non-existent-class-constant' => 2,
];
$expected[false]['tests/fixtures/IntersectionMocks.php'] = [
    'alfredbez/phpunit/assert-same-count' => 1,
    'non-existent-method' => 6,
];
$expected[true]['tests/fixtures/IntersectionMocks.php'] = [
    'non-existent-method' => 13,
    'possibly-invalid-argument' => 2,
];
$coversMethodSupported = class_exists(\PHPUnit\Framework\Attributes\CoversMethod::class);
$coversTraitSupported = class_exists(\PHPUnit\Framework\Attributes\CoversTrait::class);
$expected[false]['tests/fixtures/CoverageTargets.php'] = [
    'alfredbez/phpunit/redundant-assertion' => 1,
    'alfredbez/phpunit/phpunit-invalid-covers-target' => $phpunitMajor >= 12 ? 11 : 9,
    'non-existent-attribute-class' => ($coversMethodSupported ? 0 : 8) + ($coversTraitSupported ? 0 : 5),
];
$expected[true]['tests/fixtures/CoverageTargets.php'] = [
    'non-existent-attribute-class' => ($coversMethodSupported ? 0 : 8) + ($coversTraitSupported ? 0 : 5),
];
$expected[false]['tests/fixtures/MockMethodCalls.php'] = [
    'alfredbez/phpunit/phpunit-unknown-mocked-method' => 7,
];
$expected[true]['tests/fixtures/MockMethodCalls.php'] = [
    'uninitialized-property' => 2,
];
$expected[false]['tests/fixtures/DataProviderDeclarations.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 9,
];
$expected[true]['tests/fixtures/DataProviderDeclarations.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$stubClass = new ReflectionClass(\PHPUnit\Framework\MockObject\Stub::class);
$stubMethodNoNamedArgs = $stubClass->hasMethod('method')
    && (
        str_contains((string) $stubClass->getDocComment(), '@no-named-arguments')
        || str_contains((string) $stubClass->getMethod('method')->getDocComment(), '@no-named-arguments')
    );
$expected[false]['tests/fixtures/MockExpectationContracts.php'] = [
    'alfredbez/phpunit/phpunit-unknown-mocked-method' => 1,
    'alfredbez/phpunit/phpunit-incompatible-mock-return' => 46,
    'mixed-assignment' => 1,
    'named-argument-not-allowed' => $stubMethodNoNamedArgs ? 1 : 0,
];
$expected[true]['tests/fixtures/MockExpectationContracts.php'] = [
    'mixed-assignment' => 1,
    'named-argument-not-allowed' => $stubMethodNoNamedArgs ? 1 : 0,
];
$startsWithNonEmptyParam = str_contains(
    (string) (new ReflectionMethod(\PHPUnit\Framework\Assert::class, 'assertStringStartsWith'))->getDocComment(),
    'non-empty-string',
);
$expected[false]['tests/fixtures/Wave5Assertions.php'] = [
    'invalid-return-statement' => 3,
    'possibly-invalid-argument' => $startsWithNonEmptyParam ? 1 : 0,
];
$expected[true]['tests/fixtures/Wave5Assertions.php'] = [
    'invalid-return-statement' => 15,
    'possibly-invalid-argument' => $startsWithNonEmptyParam ? 1 : 0,
];
// PHPUnit 13.1 introduced #[DataProviderClosure]. Earlier versions report the missing attribute class,
// and the extension adds no issues there.
$closureProviderSupported = is_file('vendor/phpunit/phpunit/src/Framework/Attributes/DataProviderClosure.php');
$expected[false]['tests/fixtures/DataProviderClosurePositive.php'] = $closureProviderSupported
    ? [
        'alfredbez/phpunit/phpunit-data-provider' => 12,
    ]
    : [
        'alfredbez/phpunit/phpunit-data-provider' => 0,
        'non-existent-attribute-class' => 8,
    ];
$expected[true]['tests/fixtures/DataProviderClosurePositive.php'] = $closureProviderSupported
    ? [
        'alfredbez/phpunit/phpunit-data-provider' => 0,
    ]
    : [
        'alfredbez/phpunit/phpunit-data-provider' => 0,
        'non-existent-attribute-class' => 8,
    ];
$closureCounterIssues = $closureProviderSupported
    ? [
        'alfredbez/phpunit/phpunit-data-provider' => 0,
        'named-argument-not-allowed' => 2,
        'undefined-variable' => 1,
        'undefined-variable-in-closure-use' => 1,
    ]
    : [
        'alfredbez/phpunit/phpunit-data-provider' => 0,
        'non-existent-attribute-class' => 9,
        'undefined-variable' => 1,
        'undefined-variable-in-closure-use' => 1,
    ];
$expected[false]['tests/fixtures/DataProviderClosureCounter.php'] = $closureCounterIssues;
$expected[true]['tests/fixtures/DataProviderClosureCounter.php'] = $closureCounterIssues;
$expected[false]['tests/fixtures/PlainMockCallContracts.php'] = [
    'alfredbez/phpunit/mock-call-on-plain-type' => 8,
    'non-existent-method' => 17,
];
$expected[true]['tests/fixtures/PlainMockCallContracts.php'] = [
    'non-existent-method' => 25,
];
$expected[false]['tests/fixtures/AliasedAssertionFunctions.php'] = [
    'alfredbez/phpunit/assert-same-count' => 1,
    'alfredbez/phpunit/redundant-assertion' => 1,
];
$expected[true]['tests/fixtures/AliasedAssertionFunctions.php'] = [];
$expected[false]['tests/fixtures/AssertionNarrowingGuards.php'] = [
    'invalid-array-access' => 3,
    'invalid-return-statement' => 1,
    'less-specific-argument' => 1,
    'mixed-assignment' => 5,
    'possibly-false-argument' => 1,
    'possibly-invalid-argument' => 5,
    'possibly-null-argument' => 2,
    'undefined-string-array-index' => 3,
];
$expected[true]['tests/fixtures/AssertionNarrowingGuards.php'] = [
    'invalid-array-access' => 3,
    'invalid-return-statement' => 10,
    'less-specific-argument' => 1,
    'mixed-assignment' => 5,
    'nullable-return-statement' => 4,
    'possibly-false-argument' => 1,
    'possibly-false-array-access' => 1,
    'possibly-invalid-argument' => 5,
    'possibly-invalid-array-access' => 1,
    'possibly-null-argument' => 2,
    'possibly-null-array-access' => 2,
    'possibly-undefined-string-array-index' => 5,
];
$expected[false]['tests/fixtures/MockExpectsAndReceiverChains.php'] = [
    'alfredbez/phpunit/phpunit-incompatible-mock-return' => 5,
    'alfredbez/phpunit/phpunit-unknown-mocked-method' => 11,
    'mixed-method-access' => 2,
];
$expected[true]['tests/fixtures/MockExpectsAndReceiverChains.php'] = [
    'mixed-method-access' => 2,
    'uninitialized-property' => 1,
];
$expected[false]['tests/fixtures/DataProviderTraitAndInherited.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 3,
];
$expected[true]['tests/fixtures/DataProviderTraitAndInherited.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[false]['tests/fixtures/CrossFileBaseProviderTestCase.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 2,
];
$expected[true]['tests/fixtures/CrossFileBaseProviderTestCase.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];
$expected[false]['tests/fixtures/CrossFileProviderTrait.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 5,
];
$expected[true]['tests/fixtures/CrossFileProviderTrait.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 0,
];

foreach ([false => 'with extension', true => '--no-extensions'] as $disabled => $label) {
    $issues = $disabled ? $without : $with;
    $failures += assertHistograms($label, $issues, $expected[$disabled]);
    foreach (['This', 'Self', 'Static', 'Assert'] as $call) {
        foreach (['nullable-return-statement', 'invalid-return-statement'] as $code) {
            $failures += assertMessageCount($label, $issues, 'tests/fixtures/HasKeyNullable.php', $code, 'test' . $call . 'DoesNotExcludeNull', 1);
        }
    }
    foreach (['BeforeMissingAssignmentTest', 'AfterDoesNotInitializeTest', 'UnrelatedBeforeTest', 'BeforeNotTestCase'] as $class) {
        $failures += assertMessageCount($label, $issues, 'tests/fixtures/BeforeInitializer.php', 'uninitialized-property', $class, 1);
    }
}

$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/AssertionsParity.php',
    'undefined-string-array-index',
    "key 'k'",
    2,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/AssertionNarrowingGuards.php',
    'invalid-return-statement',
    'expected `int(3)`, but found `int(1)|int(2)`',
    1,
);
$failures += assertMessageCount(
    '--no-extensions',
    $without,
    'tests/fixtures/AssertionsParity.php',
    'undefined-string-array-index',
    "key 'k'",
    0,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/AssertionsParity.php',
    'invalid-return-statement',
    'testCountZeroDoesNotNarrowNonEmpty',
    1,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/AssertionsParity.php',
    'invalid-return-statement',
    'testCountNonLiteralDoesNotNarrowNonEmpty',
    1,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/AssertionsParity.php',
    'invalid-return-statement',
    'testStringContainsEmptyNeedleDoesNotNarrow',
    1,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/AssertionsParity.php',
    'invalid-return-statement',
    'testStringContainsDynamicNeedleDoesNotNarrow',
    1,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/HasKeyCounter.php',
    'possibly-undefined-string-array-index',
    "key 'k'",
    1,
);
$failures += assertMessageCount(
    '--no-extensions',
    $without,
    'tests/fixtures/HasKeyCounter.php',
    'possibly-undefined-string-array-index',
    "key 'k'",
    2,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/HasKeyCounter.php',
    'possibly-undefined-string-array-index',
    "key 'other'",
    1,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/SetUpCounter.php',
    'uninitialized-property',
    '$missing',
    1,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/SetUpCounter.php',
    'uninitialized-property',
    '$ready',
    0,
);
$failures += assertMessageCount(
    '--no-extensions',
    $without,
    'tests/fixtures/SetUpCounter.php',
    'uninitialized-property',
    '$ready',
    1,
);
$failures += assertMessageCount(
    '--no-extensions',
    $without,
    'tests/fixtures/SetUpPositive.php',
    'uninitialized-property',
    '$ready',
    1,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/PlainMockCallContracts.php',
    'alfredbez/phpunit/mock-call-on-plain-type',
    'PHPUnit expects() called on plain type',
    4,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/PlainMockCallContracts.php',
    'alfredbez/phpunit/mock-call-on-plain-type',
    'PHPUnit method() called on plain type',
    4,
);
foreach ([27, 28, 29, 30, 39, 40, 41, 42] as $expectedPlainLine) {
    $matchedLine = false;
    foreach ($with as $issue) {
        if (
            $issue['file'] === 'tests/fixtures/PlainMockCallContracts.php'
            && $issue['code'] === 'alfredbez/phpunit/mock-call-on-plain-type'
            && $issue['line'] === $expectedPlainLine
        ) {
            $matchedLine = true;
            break;
        }
    }
    if (!$matchedLine) {
        $failures++;
        fwrite(STDERR, "FAIL with extension  tests/fixtures/PlainMockCallContracts.php:{$expectedPlainLine} expected mock-call-on-plain-type\n");
    }
}
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/ContainsOnly.php',
    'non-existent-method',
    'PHPUnit\\Framework\\TestCase',
    1,
);
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/ContainsOnly.php',
    'non-existent-method',
    'Book',
    0,
);
foreach ([false => $with, true => $without] as $disabled => $issues) {
    $label = $disabled ? '--no-extensions' : 'with extension';
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/HasKeyArrayAccess.php',
        'invalid-return-statement',
        'testArrayObjectDoesNotBecomeArray',
        1,
    );
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/HasKeyArrayAccess.php',
        'invalid-return-statement',
        'testArrayAccessFunctionDoesNotBecomeArray',
        1,
    );
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/HasKeyArrayAccess.php',
        'non-existent-method',
        'nonExistentMethod',
        1,
    );
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/HasKeyArrayAccess.php',
        'nullable-return-statement',
        'testCustomArrayAccessValueRemainsNullable',
        1,
    );
    foreach ([
        'testEqualsThisDoesNotNarrowStringToInt',
        'testEqualsSelfDoesNotNarrowUnion',
        'testEqualsStaticDoesNotNarrowBoolUnion',
        'testEqualsAssertClassDoesNotNarrowToNull',
        'testEqualsFunctionDoesNotNarrowStringToInt',
    ] as $method) {
        $failures += assertMessageCount(
            $label,
            $issues,
            'tests/fixtures/AssertEqualsNoNarrowing.php',
            'invalid-return-statement',
            $method,
            1,
        );
    }
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/NativeAssertions.php',
        'less-specific-return-statement',
        'testInstanceOfNonLiteral',
        1,
    );
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/NativeAssertions.php',
        'invalid-return-statement',
        'testInstanceOfOtherVariable',
        1,
    );
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/NativeAssertions.php',
        'invalid-return-statement',
        'testNullOtherVariable',
        1,
    );
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/NativeAssertions.php',
        'invalid-return-statement',
        'testSameUnknownExpected',
        1,
    );
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/NativeAssertions.php',
        'invalid-return-statement',
        'testSameOtherVariable',
        1,
    );
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/NativeMocks.php',
        'non-existent-method',
        'missingMethod',
        1,
    );
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/NativeMocks.php',
        'non-existent-method',
        'expects',
        1,
    );
    $failures += assertMessageCount(
        $label,
        $issues,
        'tests/fixtures/NativeMocks.php',
        'invalid-argument',
        'PHPUnit\\Framework\\MockObject\\MockObject&AlfredBez\\MagoPhpunit\\Fixtures\\MockedDependency',
        1,
    );
}

$dataProvider = 'alfredbez/phpunit/phpunit-data-provider';
$dataProviderFile = 'tests/fixtures/DataProvider.php';
foreach ([
    'which requires 2.' => $namedProviderArgs ? 7 : 8,
    'which accepts ' => ($annotationsSupported ? 4 : 3) + ($namedProviderArgs ? 0 : 1),
    'does not provide $label' => $namedProviderArgs ? 1 : 0,
    'unknown argument $extra' => $namedProviderArgs ? 1 : 0,
    'expects an argument list' => 1,
    'for testTooManyAllowed()' => 0,
    'builtRows()' => 0,
    'for testMatching()' => 0,
    'for testNotChecked()' => 0,
    'optionalRows()' => 0,
    'variadicRows()' => 0,
    'for testTooFew()' => 1,
    'for testTooMany()' => 1,
    'yieldedRows()' => 3,
    'docblockRows()' => $annotationsSupported ? 1 : 0,
    'for testExternal()' => 1,
    'parentRows()' => 1,
    'for testRepeated()' => 1,
    'variableRows()' => 2,
    'conditionalRows()' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $dataProviderFile, $dataProvider, $needle, $count);
}
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/DataProviderLifecycleOrder.php',
    $dataProvider,
    'sharedRows() row #0 provides null|string for $value of testSetUpBeforeClassDoesNotNarrowProviderValue(), which expects string.',
    1,
);
$dataProviderDependsFile = 'tests/fixtures/DataProviderDepends.php';
foreach ([
    'for testValid()' => 0,
    'shortRows() row #0 provides 1 argument for testTooShort()' => 1,
    'wrongTypeRows() row #0 provides int for $row of testWrongType(), which expects string.' => 1,
    'longRows() row #0 provides 4 arguments for testTooLong()' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $dataProviderDependsFile, $dataProvider, $needle, $count);
}
$dataProviderDependsNamedFile = 'tests/fixtures/DataProviderDependsNamed.php';
foreach (
    $namedProviderArgs
        ? [
            'namedRows() row #0 uses named arguments with dependencies for testNamed()' => 1,
            'unknownRows() row #0 uses named arguments with dependencies for testUnknown()' => 1,
            'wrongTypeRows() row #0 uses named arguments with dependencies for testReordered()' => 1,
            'does not provide $missing for testWithoutDependency()' => 1,
        ]
        : [
            'for testNamed()' => 0,
            'for testUnknown()' => 0,
            'wrongTypeRows() row #0 provides string for $row of testReordered(), which expects int.' => 1,
            'namedRows() row #0 provides 1 argument for testWithoutDependency(), which requires 2.' => 1,
        ]
    as $needle => $count
) {
    $failures += assertMessageCount('with extension', $with, $dataProviderDependsNamedFile, $dataProvider, $needle, $count);
}
$dataProviderVariadicNamedFile = 'tests/fixtures/DataProviderVariadicNamed.php';
foreach (
    $namedProviderArgs
        ? [
            'for testValidNamedVariadic()' => 0,
            'for testReorderedNamedVariadic()' => 0,
            'for testNonLiteralNamedRowFallsBackSafely()' => 0,
            'wrongTypeVariadicRows() row #0 provides int for $rest of testWrongTypeNamedVariadic(), which expects string.' => 1,
            'wrongTypeVariadicRows() row #1 provides int for $rest of testWrongTypeNamedVariadic(), which expects string.' => 1,
            'missingRequiredVariadicRows() row #0 does not provide $n for testMissingRequiredNamedVariadic().' => 1,
        ]
        : [
            'for testValidNamedVariadic()' => 0,
            'for testNonLiteralNamedRowFallsBackSafely()' => 0,
            'reorderedNamedVariadicRows() row #0 provides string for $n of testReorderedNamedVariadic(), which expects int.' => 1,
            'reorderedNamedVariadicRows() row #0 provides int for $rest of testReorderedNamedVariadic(), which expects string.' => 1,
            'wrongTypeVariadicRows() row #0 provides int for $rest of testWrongTypeNamedVariadic(), which expects string.' => 1,
            'wrongTypeVariadicRows() row #1 provides int for $rest of testWrongTypeNamedVariadic(), which expects string.' => 1,
            'missingRequiredVariadicRows() row #0 provides string for $n of testMissingRequiredNamedVariadic(), which expects int.' => 1,
        ]
    as $needle => $count
) {
    $failures += assertMessageCount('with extension', $with, $dataProviderVariadicNamedFile, $dataProvider, $needle, $count);
}
$dataProviderTypesFile = 'tests/fixtures/DataProviderTypes.php';
foreach (
    $namedProviderArgs
        ? [
            'stringForInt() row #0 provides string for $n of testStringForInt(), which expects int.' => 1,
            'stringForInt() row "swapped" provides string for $n of testStringForInt(), which expects int.' => 1,
            'stringForInt() row "swapped" provides int for $label of testStringForInt(), which expects string.' => 1,
            'yieldedStringForInt() row #1 provides string for $n of testYieldedStringForInt(), which expects int.' => 1,
            'namedWrongType() row #0 provides float for $n of testNamedWrongType(), which expects int.' => 1,
            'variadicWrongType() row #0 provides int for $rest of testVariadicWrongType(), which expects string.' => 1,
            'compatibleRows()' => 0,
            'looseRows()' => 0,
            'nonLiteralRows()' => 0,
        ]
        : [
            'stringForInt() row #0 provides string for $n of testStringForInt(), which expects int.' => 1,
            'stringForInt() row "swapped" provides string for $n of testStringForInt(), which expects int.' => 1,
            'stringForInt() row "swapped" provides int for $label of testStringForInt(), which expects string.' => 1,
            'yieldedStringForInt() row #1 provides string for $n of testYieldedStringForInt(), which expects int.' => 1,
            'namedWrongType() row #0 provides string for $n of testNamedWrongType(), which expects int.' => 1,
            'namedWrongType() row #0 provides float for $label of testNamedWrongType(), which expects string.' => 1,
            'variadicWrongType() row #0 provides int for $rest of testVariadicWrongType(), which expects string.' => 1,
            'compatibleRows() row "named" provides' => 5,
            'looseRows()' => 0,
            'nonLiteralRows()' => 0,
        ]
    as $needle => $count
) {
    $failures += assertMessageCount('with extension', $with, $dataProviderTypesFile, $dataProvider, $needle, $count);
}
$dataProviderYieldFromFile = 'tests/fixtures/DataProviderYieldFrom.php';
foreach ([
    'wrongCountRows() row #1 provides 1 argument for testWrongCount(), which requires 2.' => 1,
    'wrongCountRows() row "tooMany" provides 3 arguments for testWrongCount(), which accepts 2.' => 1,
    ($namedProviderArgs
        ? 'namedRows() row "second" provides unknown argument $extra for testNamed().'
        : 'namedRows() row "second" provides 3 arguments for testNamed(), which accepts 2.') => 1,
    'wrongTypeRows() row #1 provides string for $n of testWrongType(), which expects int.' => 1,
    'mixedRows() row #0 provides 1 argument for testMixed(), which requires 2.' => 1,
    'mixedRows() row "fromKey" provides int for $label of testMixed(), which expects string.' => 1,
    'matchingRows()' => 0,
    'nonLiteralRows()' => 0,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $dataProviderYieldFromFile, $dataProvider, $needle, $count);
}
$dataProviderClosurePositiveFile = 'tests/fixtures/DataProviderClosurePositive.php';
$closureExpected = $closureProviderSupported ? 1 : 0;
foreach ([
    'closure() row #1 provides 1 argument for testReturnCount(), which requires 2.' => $closureExpected,
    'closure() row "tooMany" provides 3 arguments for testReturnCount(), which accepts 2.' => $closureExpected,
    'closure() row #1 provides 1 argument for testYieldCount(), which requires 2.' => $closureExpected,
    'closure() row #2 provides string for testYieldCount(), which expects an argument list.' => $closureExpected,
    'closure() row #1 provides 1 argument for testYieldFromCount(), which requires 2.' => $closureExpected,
    'closure() row #1 does not provide $label for testNamedParameters().' => $closureExpected,
    'closure() row "extraKey" provides unknown argument $extra for testNamedParameters().' => $closureExpected,
    'closure() row #0 provides string for $n of testWrongTypes(), which expects int.' => $closureExpected,
    'closure() row #0 provides int for $label of testWrongTypes(), which expects string.' => $closureExpected,
    'closure() row "fromWrong" provides float for $n of testWrongTypes(), which expects int.' => $closureExpected,
    'closure() row #0 provides 1 argument for testMixedWithMethod(), which requires 2.' => $closureExpected,
    'closure() row #0 provides string for $n of testValidateCountFalseKeepsTypeCheck(), which expects int.' => $closureExpected,
    'testValidateCountFalseKeepsTypeCheck(), which accepts' => 0,
    'validMethodRows()' => 0,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $dataProviderClosurePositiveFile, $dataProvider, $needle, $count);
}
$dataProviderClosureCounterFile = 'tests/fixtures/DataProviderClosureCounter.php';
foreach ([
    'testMatching()' => 0,
    'testTooManyAllowedPositional()' => 0,
    'testTooManyAllowedNamed()' => 0,
    'testNonLiteralBody()' => 0,
    'testClosureWithUse()' => 0,
    'testNotChecked()' => 0,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $dataProviderClosureCounterFile, $dataProvider, $needle, $count);
}
$dependencyVariantsFile = 'tests/fixtures/DataProviderDependencyVariants.php';
foreach ([
    'for testDependsDeepClone()' => 0,
    'for testDependsShallowClone()' => 0,
    'for testDependsExternal()' => 0,
    'for testDependsExternalDeepClone()' => 0,
    'for testDependsExternalShallowClone()' => 0,
    'singleRows() row #0 provides 1 argument for testDependsOnClassDoesNotSupplyArgument(), which requires 2.' => 1,
    'singleRows() row #0 provides 1 argument for testDependsOnClassDeepCloneDoesNotSupplyArgument(), which requires 2.' => 1,
    'singleRows() row #0 provides 1 argument for testDependsOnClassShallowCloneDoesNotSupplyArgument(), which requires 2.' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $dependencyVariantsFile, $dataProvider, $needle, $count);
}
$dependencyReturnTypesFile = 'tests/fixtures/DependencyReturnTypes.php';
foreach ([
    'for testValidStandalone()' => 0,
    'for testOrderingOnlyNoParams()' => 0,
    'of testVoidSkippedBeforeInt()' => $skipsVoidDep ? 0 : 1,
    'for testNullableIntoNullable()' => 0,
    'for testMixedAndUntypedAreConservative()' => 0,
    'for testValidProviderAndDeps()' => 0,
    'Dependency testIntProducer() provides int for $expected of testMismatchStandalone(), which expects string.' => 1,
    'Dependency testIntProducer() provides int for $expected of testMismatchDeepClone(), which expects string.' => 1,
    'Dependency testIntProducer() provides int for $expected of testMismatchShallowClone(), which expects string.' => 1,
    'Dependency AlfredBez\\MagoPhpunit\\Fixtures\\ExternalTypedProducerTest::testReturnInt() provides int for $expected of testMismatchExternal(), which expects string.' => 1,
    'Dependency AlfredBez\\MagoPhpunit\\Fixtures\\ExternalTypedProducerTest::testReturnInt() provides int for $expected of testMismatchExternalDeepClone(), which expects string.' => 1,
    'Dependency AlfredBez\\MagoPhpunit\\Fixtures\\ExternalTypedProducerTest::testReturnInt() provides int for $expected of testMismatchExternalShallowClone(), which expects string.' => 1,
    'Dependency testNullableStringProducer() provides null|string for $expected of testMismatchNullableIntoNonNull(), which expects string.' => 1,
    'Dependency testIntProducer() provides int for $dep of testMismatchAfterProvider(), which expects string.' => 1,
    'Dependency AlfredBez\\MagoPhpunit\\Fixtures\\ExternalTypedProducerTest::testReturnInt() provides int for $dep of testMismatchAfterTestWith(), which expects string.' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $dependencyReturnTypesFile, $dataProvider, $needle, $count);
}
$crossFileProvider = 'tests/fixtures/ExternalCrossFileProvider.php';
foreach ([
    'for testCrossFileValid()' => 0,
    'invalidRows() row #0 provides 1 argument for testCrossFileInvalid(), which requires 2.' => 1,
    'invalidRows() row #1 provides string for $n of testCrossFileInvalid(), which expects int.' => 1,
    'invalidRows() row #2 provides 3 arguments for testCrossFileInvalid(), which accepts 2.' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $crossFileProvider, $dataProvider, $needle, $count);
}
$testWithFile = 'tests/fixtures/TestWithAttributes.php';
foreach ([
    'Attribute #[TestWith] row #1 provides 1 argument for testPositionalTestWith(), which requires 2.' => 1,
    'Attribute #[TestWith] row #2 provides 3 arguments for testPositionalTestWith(), which accepts 2.' => 1,
    'Attribute #[TestWith] row #3 provides string for $n of testPositionalTestWith(), which expects int.' => 1,
    ($namedProviderArgs
        ? 'Attribute #[TestWith] row #1 does not provide $label for testNamedTestWith().'
        : 'Attribute #[TestWith] row #1 provides 1 argument for testNamedTestWith(), which requires 2.') => 1,
    ($namedProviderArgs
        ? 'Attribute #[TestWith] row #2 provides unknown argument $extra for testNamedTestWith().'
        : 'Attribute #[TestWith] row #2 provides 3 arguments for testNamedTestWith(), which accepts 2.') => 1,
    'Attribute #[TestWith] row #3 provides string for $n of testNamedTestWith(), which expects int.' => 1,
    'for testTestWithAndDependency()' => 0,
    'Attribute #[TestWithJson] row #1 provides 1 argument for testTestWithJson(), which requires 2.' => 1,
    'Attribute #[TestWithJson] row #2 provides 3 arguments for testTestWithJson(), which accepts 2.' => 1,
    "Attribute #[TestWithJson] row #3 provides 'wrong' for \$n of testTestWithJson(), which expects int." => 1,
    ($namedProviderArgs
        ? 'Attribute #[TestWithJson] row #5 does not provide $label for testTestWithJson().'
        : 'Attribute #[TestWithJson] row #5 provides 1 argument for testTestWithJson(), which requires 2.') => 1,
    'Attribute #[TestWithJson] row #6 for testTestWithJson() contains invalid JSON.' => 1,
    'Attribute #[TestWithJson] row #7 provides int for testTestWithJson(), which expects an argument list.' => 1,
    'for testUnitEnumTestWith()' => 0,
    'for testBackedEnumTestWith()' => 0,
    'for testBackedEnumValueTestWith()' => 0,
    'for testIntBackedEnumValueTestWith()' => 0,
    'Attribute #[TestWith] row #0 provides AlfredBez\\MagoPhpunit\\Fixtures\\TestWithStringMode::Active for $mode of testWrongEnumTestWith(), which expects AlfredBez\\MagoPhpunit\\Fixtures\\TestWithUnitMode.' => 1,
    'Attribute #[TestWith] row #0 provides AlfredBez\\MagoPhpunit\\Fixtures\\TestWithUnitMode::Fast for $label of testEnumForStringTestWith(), which expects string.' => 1,
    'Attribute #[TestWith] row #0 provides int(1) for $label of testIntBackedEnumValueForStringTestWith(), which expects string.' => 1,
    'Attribute #[TestWith] row #0 provides int(1) for $modeCode of testIntBackedEnumWrongLiteralValueTestWith(), which expects int.' => 1,
    'for testValidIntClassConstantTestWith()' => 0,
    'for testValidStringClassConstantTestWith()' => 0,
    'for testValidAliasClassConstantTestWith()' => 0,
    'for testValidParentClassConstantTestWith()' => 0,
    'for testValidInterfaceClassConstantTestWith()' => 0,
    'for testValidTraitClassConstantTestWith()' => 0,
    'for testValidDocNarrowedClassConstantTestWith()' => 0,
    'for testValidEnumInUnionTestWith()' => 0,
    'for testValidEnumClassConstantTestWith()' => 0,
    'for testValidEnumCaseAliasConstantTestWith()' => 0,
    'for testCyclicClassConstantTestWithIsConservative()' => 0,
    'for testUnknownClassConstantControlTestWith()' => 0,
    'for testValidWholeRowClassConstantTestWith()' => 0,
    'for testMissingWholeRowClassConstantControlTestWith()' => 0,
    'Attribute #[TestWith] row #0 provides int for $value of testInvalidWholeRowClassConstantTestWith(), which expects string.' => 1,
    'Attribute #[TestWith] row #0 provides int(42) for $value of testInvalidIntClassConstantForStringTestWith(), which expects string.' => 1,
    "Attribute #[TestWith] row #0 provides 'valid' for \$value of testInvalidStringClassConstantForIntTestWith(), which expects int." => 1,
    'Attribute #[TestWith] row #0 provides int(42) for $value of testInvalidAliasClassConstantForStringTestWith(), which expects string.' => 1,
    "Attribute #[TestWith] row #0 provides 'parent' for \$value of testInvalidParentClassConstantForIntTestWith(), which expects int." => 1,
    "Attribute #[TestWith] row #0 provides 'iface' for \$value of testInvalidInterfaceClassConstantForIntTestWith(), which expects int." => 1,
    'Attribute #[TestWith] row #0 provides int(7) for $value of testInvalidTraitClassConstantForStringTestWith(), which expects string.' => 1,
    'Attribute #[TestWith] row #0 provides int(200) for $code of testInvalidEnumClassConstantForStringTestWith(), which expects string.' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $testWithFile, $dataProvider, $needle, $count);
}
$coversCode = 'alfredbez/phpunit/phpunit-invalid-covers-target';
foreach ([
    '#[CoversClass] target class `AlfredBez\\MagoPhpunit\\Fixtures\\NonExistentCoveredClass` does not exist.' => 1,
    '#[UsesClass] target class `AlfredBez\\MagoPhpunit\\Fixtures\\NonExistentUsedClass` does not exist.' => 1,
    '#[CoversTrait] target trait `AlfredBez\\MagoPhpunit\\Fixtures\\NonExistentCoveredTrait` does not exist.' => 1,
    '#[UsesTrait] target trait `AlfredBez\\MagoPhpunit\\Fixtures\\NonExistentUsedTrait` does not exist.' => 1,
    '#[CoversMethod] target method `AlfredBez\\MagoPhpunit\\Fixtures\\CoverageSubject::nonExistentCoveredMethod()` does not exist.' => 1,
    '#[UsesMethod] target method `AlfredBez\\MagoPhpunit\\Fixtures\\CoverageSubject::nonExistentUsedMethod()` does not exist.' => 1,
    '#[CoversFunction] target function `AlfredBez\\MagoPhpunit\\Fixtures\\nonExistentCoveredFunction` does not exist.' => 1,
    '#[UsesFunction] target function `AlfredBez\\MagoPhpunit\\Fixtures\\nonExistentUsedFunction` does not exist.' => 1,
    '#[CoversFunction] target function `coverageHelperFunction` does not exist.' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, 'tests/fixtures/CoverageTargets.php', $coversCode, $needle, $count);
}
$mockMethodCode = 'alfredbez/phpunit/phpunit-unknown-mocked-method';
foreach ([
    'Trying to mock an undefined method `AlfredBez\\MagoPhpunit\\Fixtures\\MockedMailer::sendInvoce()`.' => 3,
    'Trying to mock an undefined method `AlfredBez\\MagoPhpunit\\Fixtures\\MockedMailer::missingOnStubProperty()`.' => 1,
    'Trying to mock an undefined method `AlfredBez\\MagoPhpunit\\Fixtures\\MockedNumbers::missingOnTraversable()`.' => 1,
    'Trying to mock an undefined method `AlfredBez\\MagoPhpunit\\Fixtures\\MockedLetters::current()`.' => 1,
    'Trying to mock an undefined method `AlfredBez\\MagoPhpunit\\Fixtures\\MockedNotifier::sendInvoice()`.' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, 'tests/fixtures/MockMethodCalls.php', $mockMethodCode, $needle, $count);
}
foreach ([
    'Data provider method `AlfredBez\\MagoPhpunit\\Fixtures\\DataProviderDeclarationsTest::missingRows()` referenced by testMissingLocal() does not exist.' => 1,
    'Data provider method `AlfredBez\\MagoPhpunit\\Fixtures\\DataProviderDeclarationsTest::privateRows()` referenced by testPrivateLocal() must be public.' => 1,
    'Data provider method `AlfredBez\\MagoPhpunit\\Fixtures\\DataProviderDeclarationsTest::protectedRows()` referenced by testProtectedLocal() must be public.' => 1,
    'Data provider method `AlfredBez\\MagoPhpunit\\Fixtures\\DataProviderDeclarationsTest::nonStaticRows()` referenced by testNonStaticLocal() must be static.' => 1,
    'Data provider `ExternalDeclarationProvider::validExternal` in #[DataProvider] on testInvalidClassColonSyntax() cannot use Class::method syntax; use #[DataProviderExternal] instead.' => 1,
    'Data provider method `AlfredBez\\MagoPhpunit\\Fixtures\\ExternalDeclarationProvider::missingExternal()` referenced by testMissingExternalMethod() does not exist.' => 1,
    'Data provider method `AlfredBez\\MagoPhpunit\\Fixtures\\ExternalDeclarationProvider::nonStaticExternal()` referenced by testNonStaticExternalMethod() must be static.' => 1,
    'Data provider method `AlfredBez\\MagoPhpunit\\Fixtures\\ExternalDeclarationProvider::protectedExternal()` referenced by testProtectedExternalMethod() must be public.' => 1,
    'Data provider class `AlfredBez\\MagoPhpunit\\Fixtures\\MissingExternalProviderClass` referenced by #[DataProviderExternal] on testMissingExternalClassLiteral() does not exist.' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, 'tests/fixtures/DataProviderDeclarations.php', $dataProvider, $needle, $count);
}
$incompatibleReturnCode = 'alfredbez/phpunit/phpunit-incompatible-mock-return';
$failures += assertMessageCount(
    'with extension',
    $with,
    'tests/fixtures/MockExpectationContracts.php',
    $mockMethodCode,
    'Trying to configure undefined method `AlfredBez\\MagoPhpunit\\Fixtures\\ConcreteBillingGateway::nonExistentBuilderMethod()` with MockBuilder::onlyMethods().',
    1,
);
foreach ([
    'Argument #1 of willReturn() provides `int` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 3,
    'Argument #1 of willReturn() provides `null` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 1,
    'Argument #1 of willReturn() provides `string` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::findCustomer()`, whose declared return type is `AlfredBez\\MagoPhpunit\\Fixtures\\BillingCustomer|null`.' => 1,
    'Method `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::reset()` has return type `void` and cannot be configured with willReturn().' => 1,
    'Argument #2 of willReturnOnConsecutiveCalls() provides `int` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 2,
    'Argument #4 of willReturnOnConsecutiveCalls() provides `array` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 1,
    'Argument #5 of willReturnOnConsecutiveCalls() provides `int` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 1,
    'Argument #6 of willReturnOnConsecutiveCalls() provides `null` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 1,
    'Argument #7 of willReturnOnConsecutiveCalls() provides `int` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 1,
    'Argument #8 of willReturnOnConsecutiveCalls() provides `int` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 1,
    'Argument #9 of willReturnOnConsecutiveCalls() provides `int` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 1,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `int` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 11,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `int` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::echoText()`, whose declared return type is `string`.' => 2,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `int` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::legacyArgs()`, whose declared return type is `string`.' => 1,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `null` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::legacyArgs()`, whose declared return type is `string`.' => 0,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `null` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 2,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 4,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::unrelatedCustomer()`, whose declared return type is `AlfredBez\\MagoPhpunit\\Fixtures\\BillingCustomer`.' => 1,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway&AlfredBez\\MagoPhpunit\\Fixtures\\SecondaryBillingGateway` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway&AlfredBez\\MagoPhpunit\\Fixtures\\SecondaryBillingGateway::charge()`, whose declared return type is `string`.' => 1,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `int|string` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::pick()`, whose declared return type is `string`.' => 2,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `null|string` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::requireText()`, whose declared return type is `string`.' => 1,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `null` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::echoText()`, whose declared return type is `string`.' => 2,
    'Argument #1 of willReturnOnConsecutiveCalls() provides `int` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::format()`, whose declared return type is `string`.' => 1,
    'ReturnStub' => 0,
    'ReturnCallback' => 0,
    'ReturnSelf' => 0,
    'ReturnArgument' => 0,
    'ReturnValueMap' => 0,
    'ReturnReference' => 0,
    'Stub\\ConsecutiveCalls' => 0,
    'Argument #1 of willReturn() provides `PHPUnit\\Framework\\MockObject\\Stub\\Exception` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::charge()`, whose declared return type is `string`.' => 1,
    'Method `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::abort()` has return type `never` and cannot be configured with willReturnOnConsecutiveCalls().' => 2,
    'Argument #1 of willReturn() provides `string` for `AlfredBez\\MagoPhpunit\\Fixtures\\BillingGateway::rate()`, whose declared return type is `float`.' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, 'tests/fixtures/MockExpectationContracts.php', $incompatibleReturnCode, $needle, $count);
}
foreach ($with as $issue) {
    if (
        (
            $issue['file'] === $dataProviderFile
            || $issue['file'] === $dataProviderTypesFile
            || $issue['file'] === $dataProviderYieldFromFile
            || $issue['file'] === $dataProviderClosurePositiveFile
            || $issue['file'] === $dataProviderClosureCounterFile
            || $issue['file'] === $dependencyVariantsFile
            || $issue['file'] === $crossFileProvider
            || $issue['file'] === $testWithFile
        )
        && $issue['code'] === $dataProvider
        && $issue['level'] !== 'Error'
    ) {
        $failures++;
        fwrite(STDERR, "FAIL with extension  {$issue['file']}  level {$issue['level']}\n");
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} assertion(s) failed\n");
    exit(1);
}

foreach (array_merge(glob(__DIR__ . '/wave8-*.php') ?: [], [__DIR__ . '/contains-only-function.php', __DIR__ . '/worker-and-lifecycle.php']) as $contract) {
    $result = run([PHP_BINARY, $contract]);
    fwrite(STDOUT, $result['stdout']);
    fwrite(STDERR, $result['stderr']);
    if ($result['exit'] !== 0) {
        exit(1);
    }
}

fwrite(STDOUT, "PASS\n");
exit(0);

/**
 * @param list<string> $command
 * @return array{exit: int, stdout: string, stderr: string}
 */
function run(array $command): array
{
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if ($process === false) {
        fwrite(STDERR, 'failed to start ' . implode(' ', $command) . "\n");
        exit(1);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

/** @return list<array{level: string, code: string, file: string, line: int, message: string}> */
function analyze(bool $noExtensions): array
{
    $magoBinEnv = getenv('MAGO_BIN');
    $command = ($magoBinEnv !== false && $magoBinEnv !== '')
        ? [$magoBinEnv, 'analyze', '--reporting-format=json']
        : [PHP_BINARY, 'vendor/bin/mago', 'analyze', '--reporting-format=json'];
    if ($noExtensions) {
        $command[] = '--no-extensions';
    }

    $result = run($command);
    if (trim($result['stderr']) !== '') {
        fwrite(STDERR, ($noExtensions ? '--no-extensions' : 'with extension') . " stderr:\n" . $result['stderr'] . "\n");
    }

    try {
        $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        fwrite(STDERR, "mago analyze did not return JSON (exit {$result['exit']}): {$exception->getMessage()}\n");
        fwrite(STDERR, $result['stdout'] . "\n");
        exit(1);
    }

    if (!is_array($decoded) || !isset($decoded['issues']) || !is_array($decoded['issues'])) {
        fwrite(STDERR, "mago analyze JSON has no issues array\n");
        exit(1);
    }

    $issues = [];
    foreach ($decoded['issues'] as $issue) {
        $primary = $issue['annotations'][0];
        foreach ($issue['annotations'] as $annotation) {
            if (($annotation['kind'] ?? '') === 'Primary') {
                $primary = $annotation;
                break;
            }
        }

        $start = $primary['span']['start'];
        $line = array_key_exists('column', $start)
            ? (int) $start['line']
            : (int) $start['line'] + 1;

        $issues[] = [
            'level' => (string) $issue['level'],
            'code' => (string) $issue['code'],
            'file' => (string) $primary['span']['file_id']['name'],
            'line' => $line,
            'message' => (string) $issue['message'],
        ];
    }

    return $issues;
}

/** @param list<array{level: string, code: string, file: string, line: int, message: string}> $issues */
function printIssues(array $issues): void
{
    if ($issues === []) {
        fwrite(STDOUT, "  (none)\n");

        return;
    }

    foreach ($issues as $issue) {
        fwrite(STDOUT, sprintf(
            "  %s\t%s\t%s:%d\t%s\n",
            $issue['level'],
            $issue['code'],
            $issue['file'],
            $issue['line'],
            $issue['message'],
        ));
    }
}

/**
 * @param list<array{level: string, code: string, file: string, line: int, message: string}> $issues
 * @param array<string, array<string, int>> $expected
 */
function assertHistograms(string $label, array $issues, array $expected): int
{
    $actual = [];
    foreach ($issues as $issue) {
        $actual[$issue['file']][$issue['code']] = ($actual[$issue['file']][$issue['code']] ?? 0) + 1;
    }

    $failures = 0;
    $files = array_unique([...array_keys($expected), ...array_keys($actual)]);
    sort($files);
    foreach ($files as $file) {
        $expectedCounts = $expected[$file] ?? [];
        $actualCounts = $actual[$file] ?? [];
        ksort($expectedCounts);
        ksort($actualCounts);
        $expectedCounts = array_filter($expectedCounts, static fn (int $count): bool => $count !== 0);
        if ($expectedCounts == $actualCounts) {
            fwrite(STDOUT, "ok  {$label}  {$file}  " . formatCounts($actualCounts) . "\n");
            continue;
        }

        $failures++;
        fwrite(STDERR, "FAIL {$label}  {$file}\n");
        fwrite(STDERR, '  expected ' . formatCounts($expectedCounts) . "\n");
        fwrite(STDERR, '  actual   ' . formatCounts($actualCounts) . "\n");
    }

    return $failures;
}

/**
 * @param list<array{level: string, code: string, file: string, line: int, message: string}> $issues
 */
function assertMessageCount(string $label, array $issues, string $file, string $code, string $needle, int $expected): int
{
    $actual = 0;
    foreach ($issues as $issue) {
        if ($issue['file'] === $file && $issue['code'] === $code && str_contains($issue['message'], $needle)) {
            $actual++;
        }
    }

    if ($actual === $expected) {
        fwrite(STDOUT, "ok  {$label}  {$file}  {$code} containing \"{$needle}\" = {$actual}\n");

        return 0;
    }

    fwrite(STDERR, "FAIL {$label}  {$file}  {$code} containing {$needle}\n");
    fwrite(STDERR, "  expected {$expected}, actual {$actual}\n");

    return 1;
}

/** @param array<string, int> $counts */
function formatCounts(array $counts): string
{
    if ($counts === []) {
        return '(none)';
    }

    $parts = [];
    foreach ($counts as $code => $count) {
        $parts[] = "{$code}={$count}";
    }

    return implode(', ', $parts);
}
