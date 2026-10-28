<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$catalog = json_decode(file_get_contents($argv[1] ?? $root . '/spec/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
if (($catalog['schema_version'] ?? null) !== 1 || !is_array($catalog['sources'] ?? null) || !is_array($catalog['cases'] ?? null) || $catalog['cases'] === []) {
    fwrite(STDERR, "Expected schema_version 1, sources, and a non-empty cases array\n");
    exit(1);
}
$ids = [];
$failures = [];
foreach ($catalog['cases'] as $case) {
    $id = $case['id'] ?? '';
    if (preg_match('/^PHPUNIT-[A-Z][0-9]{3}$/', $id) !== 1 || isset($ids[$id])) {
        $failures[] = "Invalid or duplicate ID: {$id}";
    }
    $ids[$id] = true;
    foreach (['title', 'fixture', 'semantics', 'analyzer_expectation', 'negative_control', 'versions'] as $field) {
        if (!is_string($case[$field] ?? null) || trim($case[$field]) === '') {
            $failures[] = "{$id}: missing {$field}";
        }
    }
    if (!in_array($case['status'] ?? null, ['candidate', 'runtime-verified', 'red', 'partial', 'covered'], true)) {
        $failures[] = "{$id}: invalid status";
    }
    if (!in_array($case['priority'] ?? null, ['P0', 'P1', 'P2'], true)) {
        $failures[] = "{$id}: invalid priority";
    }
    if (($case['sources'] ?? []) === []) {
        $failures[] = "{$id}: no sources";
    }
    foreach ($case['sources'] ?? [] as $source) {
        if (!isset($catalog['sources'][$source]['url']) || !str_starts_with($catalog['sources'][$source]['url'], 'https://')) {
            $failures[] = "{$id}: unknown or invalid source {$source}";
        }
    }
    if (!isset($case['coverage']) || !is_array($case['coverage'])) {
        $failures[] = "{$id}: missing coverage";
    }
    if (($case['status'] ?? '') === 'covered' && ($case['coverage'] ?? []) === []) {
        $failures[] = "{$id}: covered without a test";
    }
    foreach ($case['coverage'] ?? [] as $path) {
        if (!is_file($root . '/' . $path)) {
            $failures[] = "{$id}: missing test {$path}";
        }
    }
    if (!str_starts_with($case['fixture'] ?? '', '<?php')) {
        $failures[] = "{$id}: fixture must start with a PHP opening tag";
    }
    try {
        token_get_all($case['fixture'] ?? '', TOKEN_PARSE);
    } catch (ParseError $error) {
        $failures[] = "{$id}: invalid PHP fixture: {$error->getMessage()}";
    }
}
foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
fwrite(STDOUT, count($ids) . ' spec cases, ' . count($failures) . " validation failures\n");
exit($failures === [] ? 0 : 1);
