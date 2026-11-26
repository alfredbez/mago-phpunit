<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/mago-phpunit-contains-only-fn-' . bin2hex(random_bytes(5));
mkdir($directory . '/stubs', 0777, true);
mkdir($directory . '/tests', 0777, true);

file_put_contents($directory . '/stubs/phpunit10_functions.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace PHPUnit\Framework;

class Assert
{
    /** @param iterable<mixed> $haystack */
    public static function assertContainsOnlyInstancesOf(string $className, iterable $haystack, string $message = ''): void {}
}

class TestCase extends Assert {}

/** @param iterable<mixed> $haystack */
function assertContainsOnlyInstancesOf(string $className, iterable $haystack, string $message = ''): void {}
PHP);

file_put_contents($directory . '/tests/ContainsOnlyFunctionFixture.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace ContainsOnlyFnFixture;

use PHPUnit\Framework\TestCase;
use function PHPUnit\Framework\assertContainsOnlyInstancesOf;

final class Book
{
    public function title(): string
    {
        return 'book';
    }
}

final class ContainsOnlyFunctionFixture extends TestCase
{
    /** @param list<mixed> $items */
    public function testFunctionNarrows(array $items): string
    {
        assertContainsOnlyInstancesOf(Book::class, $items);

        $title = '';
        foreach ($items as $item) {
            $title = $item->title();
        }

        return $title;
    }

    /** @param list<mixed> $items */
    public function testFunctionWrongClass(array $items): string
    {
        assertContainsOnlyInstancesOf(TestCase::class, $items);

        $title = '';
        foreach ($items as $item) {
            $title = $item->title();
        }

        return $title;
    }
}
PHP);

file_put_contents($directory . '/mago.toml', <<<TOML
version = "1"
php-version = "8.4"

[source]
paths = ["tests"]
includes = ["stubs"]

[analyzer]
find-unused-definitions = false
find-unused-expressions = false

[extension-hosts.phpunit]
command = ["php", "{$root}/bin/mago-phpunit-worker"]
workers = 1
TOML);

$magoBinEnv = getenv('MAGO_BIN');
$magoCommand = ($magoBinEnv !== false && $magoBinEnv !== '')
    ? [$magoBinEnv]
    : [PHP_BINARY, $root . '/vendor/bin/mago'];

$failures = 0;
foreach ([false => 'with extension', true => '--no-extensions'] as $disabled => $label) {
    $cmd = [...$magoCommand, '--workspace', $directory, 'analyze', '--reporting-format=json'];
    if ($disabled) {
        $cmd[] = '--no-extensions';
    }
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit > 1 || str_contains($stderr, 'extension host') || str_contains($stderr, 'ERROR')) {
        fwrite(STDERR, "FAIL {$label}: exit={$exit} stderr={$stderr}\n");
        $failures++;
        continue;
    }
    $issues = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['issues'];
    $counts = [];
    foreach ($issues as $issue) {
        $counts[$issue['code']] = ($counts[$issue['code']] ?? 0) + 1;
    }
    ksort($counts);
    $expected = $disabled
        ? ['mixed-assignment' => 4, 'mixed-method-access' => 2, 'mixed-return-statement' => 2]
        : ['mixed-assignment' => 1, 'mixed-return-statement' => 1, 'non-existent-method' => 1];
    if ($counts !== $expected) {
        fwrite(STDERR, "FAIL {$label}: expected " . json_encode($expected) . ' got ' . json_encode($counts) . "\n");
        $failures++;
    }
}

@unlink($directory . '/stubs/phpunit10_functions.php');
@unlink($directory . '/tests/ContainsOnlyFunctionFixture.php');
@unlink($directory . '/mago.toml');
@rmdir($directory . '/stubs');
@rmdir($directory . '/tests');
@rmdir($directory);

fwrite(STDOUT, "contains-only-function (PHPUnit 10.5 stub): {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
