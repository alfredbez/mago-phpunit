<?php

declare(strict_types=1);

$catalog = json_decode((string) file_get_contents(__DIR__ . '/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$failures = 0;
foreach ($catalog['sources'] as $key => $source) {
    $url = (string) ($source['url'] ?? '');
    if (preg_match('~^https://github\.com/([^/]+/[^/]+)/blob/([a-f0-9]{40})/(.+)$~', $url, $match) !== 1) {
        fwrite(STDOUT, "skip {$key} (non-blob url)\n");
        continue;
    }
    if (!is_string($source['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $source['sha256']) !== 1) {
        fwrite(STDERR, "{$key}: missing sha256 fingerprint\n");
        $failures++;
        continue;
    }
    [, $repo, $ref, $path] = $match;
    $process = proc_open(
        ['gh', 'api', "repos/{$repo}/contents/{$path}?ref={$ref}", '-H', 'Accept: application/vnd.github.raw+json'],
        [1 => ['pipe', 'w'], 2 => STDERR],
        $pipes,
    );
    if ($process === false) {
        fwrite(STDERR, "{$key}: could not start gh\n");
        $failures++;
        continue;
    }
    $body = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    if ($exit !== 0 || $body === false || hash('sha256', $body) !== $source['sha256']) {
        fwrite(STDERR, "{$key}: fetch failed or source fingerprint changed\n");
        $failures++;
        continue;
    }
    fwrite(STDOUT, "ok {$key} {$source['sha256']}\n");
}
exit($failures === 0 ? 0 : 1);
