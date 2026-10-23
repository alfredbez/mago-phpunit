<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

$failures = 0;

$version = trim(run([PHP_BINARY, 'vendor/bin/mago', '--version'])['stdout']);
$phpunit = trim(run([PHP_BINARY, 'vendor/bin/phpunit', '--version'])['stdout']);
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
