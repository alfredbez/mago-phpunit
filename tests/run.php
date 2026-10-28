<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

$failures = 0;

$version = trim(run([PHP_BINARY, 'vendor/bin/mago', '--version'])['stdout']);
$phpunit = trim(run([PHP_BINARY, 'vendor/bin/phpunit', '--version'])['stdout']);
$phpunitMajor = 0;
if (preg_match('/PHPUnit (\d+)/', $phpunit, $phpunitMatch) === 1) {
    $phpunitMajor = (int) $phpunitMatch[1];
}
fwrite(STDOUT, $version . "\n" . $phpunit . "\n\n");

$validated = run([PHP_BINARY, 'vendor/bin/mago', 'extension', 'validate']);
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
        'tests/fixtures/SetUpPositive.php' => [
            'uninitialized-property' => 0,
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
        'tests/fixtures/SetUpPositive.php' => [
            'uninitialized-property' => 1,
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
        'mixed-assignment' => 12,
        'mixed-method-access' => 6,
        'mixed-return-statement' => 6,
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
// PHPUnit 12 removed doc-comment metadata, so the @dataProvider fixture is only checked before 12.
$annotationsSupported = $phpunitMajor < 12;
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
$expected[false]['tests/fixtures/DataProviderTypes.php'] = [
    'alfredbez/phpunit/phpunit-data-provider' => 6,
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

foreach ([false => 'with extension', true => '--no-extensions'] as $disabled => $label) {
    $issues = $disabled ? $without : $with;
    $failures += assertHistograms($label, $issues, $expected[$disabled]);
}

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
}

$dataProvider = 'alfredbez/phpunit/phpunit-data-provider';
$dataProviderFile = 'tests/fixtures/DataProvider.php';
foreach ([
    'which requires 2.' => 7,
    'which accepts ' => $annotationsSupported ? 4 : 3,
    'does not provide $label' => 1,
    'unknown argument $extra' => 1,
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
$dataProviderDependsFile = 'tests/fixtures/DataProviderDepends.php';
foreach ([
    'for testValid()' => 0,
    'shortRows() row #0 provides 1 argument for testTooShort()' => 1,
    'wrongTypeRows() row #0 provides int for $row of testWrongType(), which expects string.' => 1,
    'longRows() row #0 provides 4 arguments for testTooLong()' => 1,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $dataProviderDependsFile, $dataProvider, $needle, $count);
}
$dataProviderTypesFile = 'tests/fixtures/DataProviderTypes.php';
foreach ([
    'stringForInt() row #0 provides string for $n of testStringForInt(), which expects int.' => 1,
    'stringForInt() row "swapped" provides string for $n of testStringForInt(), which expects int.' => 1,
    'stringForInt() row "swapped" provides int for $label of testStringForInt(), which expects string.' => 1,
    'yieldedStringForInt() row #1 provides string for $n of testYieldedStringForInt(), which expects int.' => 1,
    'namedWrongType() row #0 provides float for $n of testNamedWrongType(), which expects int.' => 1,
    'variadicWrongType() row #0 provides int for $rest of testVariadicWrongType(), which expects string.' => 1,
    'compatibleRows()' => 0,
    'looseRows()' => 0,
    'nonLiteralRows()' => 0,
] as $needle => $count) {
    $failures += assertMessageCount('with extension', $with, $dataProviderTypesFile, $dataProvider, $needle, $count);
}
$dataProviderYieldFromFile = 'tests/fixtures/DataProviderYieldFrom.php';
foreach ([
    'wrongCountRows() row #1 provides 1 argument for testWrongCount(), which requires 2.' => 1,
    'wrongCountRows() row "tooMany" provides 3 arguments for testWrongCount(), which accepts 2.' => 1,
    'namedRows() row "second" provides unknown argument $extra for testNamed().' => 1,
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
foreach ($with as $issue) {
    if (
        (
            $issue['file'] === $dataProviderFile
            || $issue['file'] === $dataProviderTypesFile
            || $issue['file'] === $dataProviderYieldFromFile
            || $issue['file'] === $dataProviderClosurePositiveFile
            || $issue['file'] === $dataProviderClosureCounterFile
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
    $command = [PHP_BINARY, 'vendor/bin/mago', 'analyze', '--reporting-format=json'];
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

        $issues[] = [
            'level' => (string) $issue['level'],
            'code' => (string) $issue['code'],
            'file' => (string) $primary['span']['file_id']['name'],
            'line' => (int) $primary['span']['start']['line'],
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
