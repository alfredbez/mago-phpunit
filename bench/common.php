<?php

declare(strict_types=1);

function analyzeBenchmark(): array
{
    $command = [PHP_BINARY, dirname(__DIR__) . '/vendor/bin/mago', '--config', __DIR__ . '/mago.toml', '--threads', '1', 'analyze', '--reporting-format', 'json'];
    $stdout = tempnam(sys_get_temp_dir(), 'mago-bench-out-');
    $stderr = tempnam(sys_get_temp_dir(), 'mago-bench-err-');
    $start = hrtime(true);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start Mago');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $elapsed = (hrtime(true) - $start) / 1e6;
    $output = file_get_contents($stdout);
    $errors = file_get_contents($stderr);
    unlink($stdout);
    unlink($stderr);
    if ($exit !== 0 || (json_decode($output, true, 512, JSON_THROW_ON_ERROR)['issues'] ?? null) !== []) {
        throw new RuntimeException("Benchmark analysis failed (exit {$exit}):\n{$output}\n{$errors}");
    }
    return ['elapsed_ms' => $elapsed];
}

function benchmarkSummary(array $samples): array
{
    $sorted = $samples;
    sort($sorted, SORT_NUMERIC);
    $count = count($sorted);
    return [
        'median_ms' => ($sorted[intdiv($count - 1, 2)] + $sorted[intdiv($count, 2)]) / 2,
        'min_ms' => $sorted[0],
        'max_ms' => $sorted[$count - 1],
        'samples_ms' => $samples,
    ];
}
