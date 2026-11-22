<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$directory = sys_get_temp_dir() . '/wave8-mocks-' . bin2hex(random_bytes(5));
mkdir($directory);
$version = \PHPUnit\Runner\Version::id();
$config = "version = \"1\"\nphp-version = \"8.4\"\n[source]\npaths = [\"{$root}/tests/wave8/mocks\"]\nincludes = [\"{$root}/vendor/phpunit/phpunit/src\"]\n[analyzer]\nfind-unused-definitions = false\nfind-unused-expressions = false\ncheck-throws = true\n[extension-hosts.phpunit]\ncommand = [\"php\", \"{$root}/bin/mago-phpunit-worker\"]\nworkers = 1\n";
file_put_contents($directory . '/mago.toml', $config);
$failures = 0;
foreach ([false, true] as $disabled) {
    $cmd = [PHP_BINARY, $root . '/vendor/bin/mago', '--workspace', $directory, 'analyze', '--reporting-format=json'];
    if ($disabled) { $cmd[] = '--no-extensions'; }
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if (trim($output) === '') { throw new RuntimeException($error . ' exit=' . $exit); }
    $issues = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['issues'] ?? null;
    if (!is_array($issues) || $exit > 1 || str_contains($error, 'extension host')) { throw new RuntimeException($error . $output); }
    foreach ([
        'legacy-method' => ['non-existent-method', $disabled ? 1 : 0],
        'legacy-expects' => ['non-existent-method', $disabled ? 1 : 0],
        'legacy-pass' => ['possibly-invalid-argument', $disabled ? 1 : 0],
        'unrelated-pass' => ['possibly-invalid-argument', 1],
        'union-wrong-target' => ['possibly-invalid-argument', 1],
        'plain-method' => [$disabled ? 'non-existent-method' : 'alfredbez/phpunit/mock-call-on-plain-type', 1],
        'plain-expects' => [$disabled ? 'non-existent-method' : 'alfredbez/phpunit/mock-call-on-plain-type', 1],
        'shadowed-union' => ['non-existent-method', 2],
        'shadowed-arrow' => ['non-existent-method', 2],
        'captured-union' => ['non-existent-method', $disabled ? 1 : 0],
        'outside-plain' => ['non-existent-method', 1],
        'allowed-method' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', 0],
        'final-method' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', $disabled ? 0 : 1],
        'private-method' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', $disabled ? 0 : 1],
        'protected-method' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', $disabled ? 0 : 1],
        'constructor-method' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', $disabled ? 0 : 1],
        'destructor-method' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', $disabled ? 0 : 1],
        'missing-method' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', $disabled ? 0 : 1],
        'partial-protected' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', 0],
        'partial-unknown' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', 0],
        'partial-final' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', $disabled ? 0 : 1],
        'partial-private' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', $disabled ? 0 : 1],
        'test-framework-exception' => ['unhandled-thrown-type', $disabled ? 1 : 0],
        'test-application-exception' => ['unhandled-thrown-type', 1],
        'outside-framework-exception' => ['unhandled-thrown-type', 1],
    ] as $marker => [$code, $expected]) {
        $actual = 0;
        foreach ($issues as $issue) {
            if ($issue['code'] !== $code) { continue; }
            $annotation = $issue['annotations'][0];
            foreach ($issue['annotations'] as $candidate) { if ($candidate['kind'] === 'Primary') { $annotation = $candidate; break; } }
            $start = $annotation['span']['start'];
            $line = isset($start['column']) ? $start['line'] : $start['line'] + 1;
            $file = $annotation['span']['file_id']['name'];
            $contents = file($file);
            if (str_contains($contents[$line - 1] ?? '', '// ' . $marker) && !str_contains($contents[$line - 1] ?? '', '// ' . $marker . '-')) { $actual++; }
        }
        if ($actual !== $expected) { fwrite(STDERR, "FAIL disabled=" . (int)$disabled . " {$marker} {$code} expected {$expected} got {$actual}\n"); $failures++; }
    }
    if (!$disabled) {
        foreach ($issues as $issue) {
            if ($issue['code'] !== 'non-existent-method') { continue; }
            $primary = $issue['annotations'][0];
            $start = $primary['span']['start'];
            $line = isset($start['column']) ? $start['line'] : $start['line'] + 1;
            $contents = file($primary['span']['file_id']['name']);
            if (str_contains($contents[$line - 1] ?? '', '// plain-')) { fwrite(STDERR, "Duplicate plain mock diagnostic\n"); $failures++; }
        }
    }
    if (!$disabled && version_compare($version, '12', '<')) {
        foreach ($issues as $issue) {
            if (str_contains(json_encode($issue), 'TraitContracts.php') && in_array($issue['code'], ['mixed-return-statement', 'invalid-return-statement', 'non-existent-method'], true)) { fwrite(STDERR, json_encode($issue) . "\n"); $failures++; }
        }
    }
    file_put_contents($directory . '/' . ($disabled ? 'without' : 'with') . '.json', $output);
}
echo "Wave 8 mock contracts {$version}: {$failures} failures ({$directory})\n";
exit($failures === 0 ? 0 : 1);
