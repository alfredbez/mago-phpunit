<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$jsonPath = $argv[1] ?? $repoRoot . '/.mago-self-report.json';
$baselinePath = $repoRoot . '/tools/self-analyze-baseline.json';

if (!is_file($jsonPath)) {
    fwrite(STDERR, "Missing self-analysis JSON: {$jsonPath}\n");
    exit(1);
}

$decoded = json_decode((string) file_get_contents($jsonPath), true);
if (!is_array($decoded) || !isset($decoded['issues']) || !is_array($decoded['issues'])) {
    fwrite(STDERR, "Self-analysis JSON has no issues array\n");
    exit(1);
}

$forbidden = [
    'non-existent-property' => true,
    'non-existent-method' => true,
    'impossible-condition' => true,
];

$byCode = [];
$forbiddenHits = [];
foreach ($decoded['issues'] as $issue) {
    $code = (string) ($issue['code'] ?? '');
    $byCode[$code] = ($byCode[$code] ?? 0) + 1;
    if (isset($forbidden[$code])) {
        $forbiddenHits[] = $issue;
    }
}

ksort($byCode);
$errorCount = 0;
foreach ($decoded['issues'] as $issue) {
    if (strtolower((string) ($issue['level'] ?? '')) === 'error') {
        $errorCount++;
    }
}

$failures = 0;
if ($forbiddenHits !== []) {
    $failures++;
    fwrite(STDERR, "FAIL self-analysis: forbidden diagnostics:\n");
    foreach ($forbiddenHits as $issue) {
        $file = $issue['annotations'][0]['span']['file_id']['name'] ?? '?';
        $line = $issue['annotations'][0]['span']['start']['line'] ?? '?';
        fwrite(STDERR, sprintf("  %s  %s:%s  %s\n", $issue['code'] ?? '', $file, $line, $issue['message'] ?? ''));
    }
}

$baseline = ['max_errors' => 0, 'by_code' => []];
if (is_file($baselinePath)) {
    $decodedBaseline = json_decode((string) file_get_contents($baselinePath), true);
    if (is_array($decodedBaseline)) {
        $baseline = $decodedBaseline;
    }
}

$maxErrors = (int) ($baseline['max_errors'] ?? 0);
if ($errorCount > $maxErrors) {
    $failures++;
    fwrite(STDERR, "FAIL self-analysis: error count {$errorCount} exceeds baseline {$maxErrors}\n");
}

$baseByCode = is_array($baseline['by_code'] ?? null) ? $baseline['by_code'] : [];
foreach ($byCode as $code => $count) {
    $allowed = (int) ($baseByCode[$code] ?? 0);
    if ($count > $allowed) {
        $failures++;
        fwrite(STDERR, "FAIL self-analysis: {$code} increased from {$allowed} to {$count}\n");
    }
}

fwrite(STDOUT, "self-analysis errors={$errorCount} codes=" . json_encode($byCode) . "\n");
exit($failures === 0 ? 0 : 1);
