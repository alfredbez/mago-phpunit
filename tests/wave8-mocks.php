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
        'aliased-legacy' => ['non-existent-method', $disabled ? 1 : 0],
        'narrowed-legacy' => ['non-existent-method', 1],
        'plain-narrowed-method' => [$disabled ? 'non-existent-method' : 'alfredbez/phpunit/mock-call-on-plain-type', 1],
        'plain-narrowed-expects' => [$disabled ? 'non-existent-method' : 'alfredbez/phpunit/mock-call-on-plain-type', 1],
        'plain-narrowed-property' => [$disabled ? 'non-existent-method' : 'alfredbez/phpunit/mock-call-on-plain-type', 1],
        'plain-narrowed-else' => [$disabled ? 'non-existent-method' : 'alfredbez/phpunit/mock-call-on-plain-type', 1],
        'narrowed-double' => ['non-existent-method', 0],
        'narrowed-unrelated' => ['non-existent-method', 2],
        'documented-property' => ['non-existent-method', $disabled ? 1 : 0],
        'test-legacy-property' => ['non-existent-method', $disabled ? 1 : 0],
        'plain-property' => [$disabled ? 'non-existent-method' : 'alfredbez/phpunit/mock-call-on-plain-type', 1],
        'unrelated-property' => ['non-existent-method', 2],
        'property-mutation' => ['non-existent-method', 1],
        'documented-global' => ['non-existent-method', $disabled ? 1 : 0],
        'documented-trait' => ['non-existent-method', $disabled ? 1 : 0],
        'sibling-shadow' => ['non-existent-method', 2],
        'after-sibling-shadow' => ['non-existent-method', $disabled ? 1 : 0],
        'captured-before-mutation' => ['non-existent-method', $disabled ? 1 : 0],
        'after-plain-mutation' => ['non-existent-method', 1],
        'foreach-mutation' => ['non-existent-method', 2],
        'reference-mutation' => ['non-existent-method', 1],
        'literal-assignment' => ['non-existent-method', $disabled ? 1 : 0],
        'global-legacy' => ['non-existent-method', $disabled ? 1 : 0],
        'global-expects' => ['non-existent-method', $disabled ? 1 : 0],
        'global-pass' => ['possibly-invalid-argument', $disabled ? 1 : 0],
        'trait-legacy' => ['non-existent-method', $disabled ? 1 : 0],
        'trait-pass' => ['possibly-invalid-argument', $disabled ? 1 : 0],
        'helper-legacy' => ['non-existent-method', $disabled ? 1 : 0],
        'helper-property' => ['non-existent-method', $disabled ? 1 : 0],
        'helper-unrelated-property' => ['non-existent-method', 2],
        'helper-plain' => ['non-existent-method', 1],
        'closure-legacy' => ['non-existent-method', $disabled ? 1 : 0],
        'arrow-legacy' => ['non-existent-method', $disabled ? 1 : 0],
        'global-captured' => ['non-existent-method', $disabled ? 1 : 0],
        'global-shadow' => ['non-existent-method', 2],
        'mutable-legacy' => ['non-existent-method', 2],
        'destructured-legacy' => ['non-existent-method', 2],
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
        'magic-final' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', $disabled ? 0 : 1],
        'magic-private' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', $disabled ? 0 : 1],
        'magic-dynamic' => ['alfredbez/phpunit/phpunit-unknown-mocked-method', 0],
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
        $positiveMarkers = ['narrowed-double', 'aliased-legacy', 'documented-property', 'test-legacy-property', 'documented-global', 'documented-trait', 'after-sibling-shadow', 'captured-before-mutation', 'literal-assignment', 'global-legacy', 'global-expects', 'global-pass', 'trait-legacy', 'trait-pass', 'helper-legacy', 'helper-property', 'closure-legacy', 'arrow-legacy', 'global-captured', 'legacy-method', 'legacy-expects', 'legacy-pass', 'captured-union', 'allowed-method', 'partial-protected', 'partial-unknown', 'magic-dynamic', 'test-framework-exception'];
        foreach ($issues as $issue) {
            $primary = $issue['annotations'][0];
            foreach ($issue['annotations'] as $candidate) { if ($candidate['kind'] === 'Primary') { $primary = $candidate; break; } }
            $start = $primary['span']['start'];
            $line = isset($start['column']) ? $start['line'] : $start['line'] + 1;
            $contents = file($primary['span']['file_id']['name']);
            foreach ($positiveMarkers as $marker) {
                if (preg_match('/\/\/ ' . preg_quote($marker, '/') . '\s*$/', $contents[$line - 1] ?? '') === 1) {
                    fwrite(STDERR, 'Unexpected positive-case diagnostic ' . $marker . ' ' . $issue['code'] . "\n");
                    $failures++;
                }
            }
        }
        foreach ($issues as $issue) {
            if (!in_array($issue['code'], ['non-existent-method', 'alfredbez/phpunit/mock-call-on-plain-type'], true)) { continue; }
            $primary = $issue['annotations'][0];
            $start = $primary['span']['start'];
            $line = isset($start['column']) ? $start['line'] : $start['line'] + 1;
            $contents = file($primary['span']['file_id']['name']);
            if ($issue['code'] === 'alfredbez/phpunit/mock-call-on-plain-type' && preg_match('/\/\/ (narrowed-legacy|narrowed-unrelated)\s*$/', $contents[$line - 1] ?? '') === 1) { fwrite(STDERR, "Unexpected custom diagnostic on application or unrelated union\n"); $failures++; }
            if ($issue['code'] === 'non-existent-method' && str_contains($contents[$line - 1] ?? '', '// plain-')) { fwrite(STDERR, "Duplicate plain mock diagnostic\n"); $failures++; }
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
