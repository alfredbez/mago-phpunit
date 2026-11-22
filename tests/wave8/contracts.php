<?php

declare(strict_types=1);

final class Wave8Contracts
{
    private array $expected = [];

    public function __construct(private readonly string $directory) {}

    public function expect(string $file, string $target, string $code, string $message, int $count = 1): void
    {
        $source = file_get_contents($this->directory . '/' . $file);
        $offset = strpos($source, $target);
        if ($offset === false || strpos($source, $target, $offset + 1) !== false) {
            throw new RuntimeException("Contract target must occur once: {$target}");
        }
        $line = substr_count(substr($source, 0, $offset), "\n") + 1;
        $this->expected[] = [$file, $line, 'alfredbez/phpunit/' . $code, $message, $count];
    }

    public function verify(): void
    {
        $process = proc_open([PHP_BINARY, 'vendor/bin/mago', 'analyze', '--reporting-format=json', $this->directory],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start Mago.');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($error !== '' || !in_array($exit, [0, 1], true)) {
            throw new RuntimeException("Mago failed: {$error}");
        }
        $issues = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['issues'];
        $actual = [];
        $failures = [];
        foreach ($issues as $issue) {
            if (str_contains($issue['code'], 'return-statement') || $issue['code'] === 'non-existent-method') {
                $failures[] = $issue['message'];
            }
            if (!str_starts_with($issue['code'], 'alfredbez/phpunit/')) {
                continue;
            }
            $primary = $issue['annotations'][0];
            foreach ($issue['annotations'] as $annotation) {
                if ($annotation['kind'] === 'Primary') {
                    $primary = $annotation;
                    break;
                }
            }
            $span = $primary['span'];
            $start = $span['start'];
            $line = (int) $start['line'] + (array_key_exists('column', $start) ? 0 : 1);
            $actual[] = [basename($span['file_id']['name']), $line, $issue['code'], $issue['message']];
        }
        foreach ($this->expected as [$file, $line, $code, $message, $count]) {
            $found = 0;
            foreach ($actual as $key => $issue) {
                if ($issue[0] === $file && $issue[1] === $line && $issue[2] === $code && $issue[3] === $message) {
                    ++$found;
                    unset($actual[$key]);
                }
            }
            if ($found !== $count) {
                $failures[] = "{$file}:{$line} {$code} message {$message}: expected {$count}, got {$found}";
            }
        }
        foreach ($actual as [$file, $line, $code, $message]) {
            $failures[] = "Unexpected {$file}:{$line} {$code}: {$message}";
        }
        foreach ($failures as $failure) {
            fwrite(STDERR, $failure . "\n");
        }
        fwrite(STDOUT, $this->directory . ': ' . count($failures) . " failures\n");
        if ($failures !== []) {
            exit(1);
        }
    }
}
