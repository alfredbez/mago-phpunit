<?php

declare(strict_types=1);

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

$fixture = 'tests/wave8/assertions/Wave8Assertions.php';
$expected = [];
$nativeEmptyLines = [];
$nativeEmpty = preg_match('/@(psalm|phpstan)-assert empty \$actual/', (new ReflectionMethod(PHPUnit\Framework\Assert::class, 'assertEmpty'))->getDocComment() ?: '') === 1;
foreach (file($fixture) as $index => $line) {
    if (str_contains($line, '@wave8-native-empty-')) {
        $nativeEmptyLines[$index + 1] = str_contains($line, '@wave8-native-empty-object') ? ['never-return'] : [];
    }
    if (preg_match('/@wave8 ([a-z-]+)/', $line, $match) === 1) {
        $expected[$index + 1] = $match[1];
    }
}
$binary = getenv('MAGO_BINARY');
$command = $binary ? [$binary] : [PHP_BINARY, 'vendor/bin/mago'];
$command = [...$command, 'analyze', '--reporting-format=json', $fixture];
$actual = analyzeWave8Assertions($command);
$failures = [];
if ($nativeEmpty) {
    $without = analyzeWave8Assertions([...$command, '--no-extensions']);
    foreach ($nativeEmptyLines as $line => $nativeExpected) {
        if (($actual[$line] ?? []) !== $nativeExpected || ($actual[$line] ?? []) !== ($without[$line] ?? [])) {
            $failures[] = "Line {$line} changed the native PHPUnit empty-assertion behavior";
        }
        unset($actual[$line], $expected[$line]);
    }
    fwrite(STDOUT, "INHERITED LIMITATION: native empty annotations discard '0' and infer never for Countable. Extension parity with --no-extensions checked.\n");
}
foreach ($expected as $line => $code) {
    if (($actual[$line] ?? []) !== [$code]) {
        $failures[] = "Line {$line} expected {$code}, got " . json_encode($actual[$line] ?? []);
    }
    unset($actual[$line]);
}
foreach ($actual as $line => $codes) {
    $failures[] = "Line {$line} has unexpected diagnostics " . json_encode($codes);
}
foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
fwrite(STDOUT, count($expected) . ' assertion counterexamples, ' . count($failures) . " failures\n");
exit($failures === [] ? 0 : 1);

/**
 * @param list<string> $command
 * @return array<int, list<string>>
 */
function analyzeWave8Assertions(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('Cannot start Mago');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $issues = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['issues'] ?? null;
    if (!is_array($issues) || $exit > 1 || trim($stderr) !== '') {
        throw new RuntimeException("Analyzer did not complete cleanly.\n" . $stderr . $stdout);
    }
    $actual = [];
    foreach ($issues as $issue) {
        $annotation = $issue['annotations'][0];
        foreach ($issue['annotations'] as $candidate) {
            if (($candidate['kind'] ?? '') === 'Primary') {
                $annotation = $candidate;
                break;
            }
        }
        $start = $annotation['span']['start'];
        $line = $start['line'] + (isset($start['column']) ? 0 : 1);
        $actual[$line][] = $issue['code'];
    }

    return $actual;
}
