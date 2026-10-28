<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$output = $argv[1] ?? throw new InvalidArgumentException('Provide an output directory');
if (!is_dir($output)) {
    mkdir($output, 0777, true);
}
$output = realpath($output);
$scratch = sys_get_temp_dir() . '/phpunit-a003-' . bin2hex(random_bytes(8));
mkdir($scratch);

$mutations = [
    'missing-registration' => [
        'src/Analyzer/PhpUnitPlugin.php',
        '$registry->registerFunctionAssertionProvider(new AssertArrayHasKeyFunctionAssertion());',
        '',
    ],
    'nonnull-instead-of-present' => [
        'src/Analyzer/Providers/AssertArrayHasKeyAssertion.php',
        'ArrayKeyAssertionKind::HasKey',
        'ArrayKeyAssertionKind::HasNonnullEntryForKey',
    ],
    'missing-integer-support' => [
        'src/Analyzer/Providers/AssertArrayHasKeyAssertion.php',
        '$integer = $type->getLiteralInt();',
        '$integer = null;',
    ],
    'unrelated-function' => [
        'src/Analyzer/Providers/AssertArrayHasKeyFunctionAssertion.php',
        "FunctionTarget::exact('PHPUnit\\\\Framework\\\\assertArrayHasKey')",
        "FunctionTarget::exact('AlfredBez\\\\MagoPhpunit\\\\Fixtures\\\\Unrelated\\\\assertArrayHasKey')",
    ],
];

function execute(array $command, string $log): int
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start command');
    }
    return proc_close($process);
}

try {
    $paths = array_map(static fn (string $path): string => $root . '/' . $path, ['src', 'tests', 'vendor', 'spec', 'composer.json', 'composer.lock', 'mago.toml']);
    if (execute(['cp', '-R', ...$paths, $scratch], $output . '/copy.log') !== 0) {
        throw new RuntimeException('Cannot copy disposable test project');
    }
    if (execute([PHP_BINARY, $scratch . '/tests/run.php'], $output . '/baseline.log') !== 0) {
        throw new RuntimeException('Mutation baseline must pass');
    }
    foreach ($mutations as $name => [$path, $before, $after]) {
        $file = $scratch . '/' . $path;
        $original = file_get_contents($file);
        $mutated = str_replace($before, $after, $original, $count);
        if ($count !== 1) {
            throw new RuntimeException("Mutation {$name} matched {$count} locations");
        }
        try {
            file_put_contents($file, $mutated);
            $log = $output . '/' . $name . '.log';
            $exit = execute([PHP_BINARY, $scratch . '/tests/run.php'], $log);
            if ($exit === 0 || !str_contains(file_get_contents($log), 'FAIL with extension  tests/fixtures/HasKeyFunction.php')) {
                throw new RuntimeException("Mutation {$name} survived or failed outside the conformance check");
            }
            fwrite(STDOUT, "KILLED {$name}\n");
        } finally {
            file_put_contents($file, $original);
        }
    }
} finally {
    execute(['rm', '-rf', $scratch], $output . '/cleanup.log');
}
