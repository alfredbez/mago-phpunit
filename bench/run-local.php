<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

$options = getopt('', ['runs:', 'output:']);
$runs = filter_var($options['runs'] ?? 7, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5]]);
if ($runs === false) {
    throw new InvalidArgumentException('--runs must be at least 5');
}
$directory = sys_get_temp_dir() . '/mago-phpunit-bench-' . bin2hex(random_bytes(8));
mkdir($directory);
$profile = $directory . '/profile.json';
putenv('BENCH_SNAPSHOT_DIR=' . $directory);
putenv('BENCH_PROFILE_PATH=' . $profile);
try {
    $version = trim(shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/vendor/bin/mago') . ' --version'));
    analyzeBenchmark();
    putenv('BENCH_SNAPSHOT_DIR');
    $samples = ['analyze' => [], 'data_provider_callbacks' => [], 'method_index' => []];
    for ($run = 0; $run < $runs; $run++) {
        unlink($profile);
        $samples['analyze'][] = analyzeBenchmark()['elapsed_ms'];
        $timing = json_decode(file_get_contents($profile), true, 512, JSON_THROW_ON_ERROR);
        if ($timing['calls'] !== 32) {
            throw new RuntimeException('Expected 32 TestCase callbacks');
        }
        $samples['data_provider_callbacks'][] = $timing['provider_ms'];
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/provider.php') . ' ' . escapeshellarg($directory);
        exec($command, $output, $exit);
        if ($exit !== 0) {
            throw new RuntimeException('Provider benchmark failed: ' . implode("\n", $output));
        }
        $samples['method_index'][] = json_decode(array_pop($output), true, 512, JSON_THROW_ON_ERROR)['elapsed_ms'];
        printf("Run %d/%d: analyze %.3f ms, callbacks %.3f ms, index %.3f ms\n", $run + 1, $runs, $samples['analyze'][$run], $samples['data_provider_callbacks'][$run], $samples['method_index'][$run]);
    }
    $result = ['php' => PHP_VERSION, 'mago' => $version, 'runs' => $runs, 'warmups' => 1, 'threads' => 1, 'workers' => 1, 'method_index_iterations' => 5];
    foreach ($samples as $name => $values) {
        $result[$name] = benchmarkSummary($values);
        printf("%s: median %.3f ms, min %.3f ms, max %.3f ms\n", $name, $result[$name]['median_ms'], $result[$name]['min_ms'], $result[$name]['max_ms']);
    }
    if (isset($options['output'])) {
        file_put_contents($options['output'], json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }
} finally {
    putenv('BENCH_SNAPSHOT_DIR');
    putenv('BENCH_PROFILE_PATH');
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
