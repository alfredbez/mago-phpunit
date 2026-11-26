<?php

declare(strict_types=1);

use AlfredBez\MagoPhpunit\Analyzer\PhpUnitPlugin;
use AlfredBez\MagoPhpunit\Analyzer\Providers\DataProviderArguments;
use AlfredBez\MagoPhpunit\Analyzer\Providers\LegacyMockSourceIndex;
use Mago\Sdk\Analyzer\BeforeAnalysisContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\TypeComparator;
use Mago\Sdk\Internal\Analyzer\MetadataCache;
use Mago\Sdk\Internal\HostClient;
use Mago\Sdk\Internal\SignalCancellationToken;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Span;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$magoBinEnv = getenv('MAGO_BIN');
$magoCommand = ($magoBinEnv !== false && $magoBinEnv !== '')
    ? [$magoBinEnv]
    : [PHP_BINARY, $root . '/vendor/bin/mago'];

$failures = 0;

// 1. Multi-worker determinism: workers = 1 vs workers = 4 on fixtures + multi-file cross-file external providers.
$mwDir = sys_get_temp_dir() . '/mago-phpunit-mw-' . bin2hex(random_bytes(5));
mkdir($mwDir . '/src', 0777, true);
try {
    file_put_contents($mwDir . '/src/SharedExternalProvider.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MultiWorkerFixtures;

final class SharedExternalProvider
{
    public static function goodRows(): array
    {
        return [
            [1, 'alpha'],
            [2, 'beta'],
        ];
    }

    public static function brokenRows(): array
    {
        return [
            [1],
            ['not-an-int', 'beta'],
        ];
    }
}
PHP);

    for ($i = 0; $i < 16; $i++) {
        $className = "SiblingExternalProvider{$i}Test";
        $body = $i === 11
            ? <<<PHP
<?php

declare(strict_types=1);

namespace MultiWorkerFixtures;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class {$className} extends TestCase
{
    #[DataProviderExternal(SharedExternalProvider::class, 'goodRows')]
    public function testValid(int \$id, string \$name): void
    {
        self::assertGreaterThan(0, \$id);
        self::assertNotSame('', \$name);
    }

    #[DataProviderExternal(SharedExternalProvider::class, 'brokenRows')]
    public function testInvalid(int \$id, string \$name): void
    {
        self::assertGreaterThan(0, \$id);
        self::assertNotSame('', \$name);
    }
}
PHP
            : <<<PHP
<?php

declare(strict_types=1);

namespace MultiWorkerFixtures;

use PHPUnit\Framework\TestCase;

final class {$className} extends TestCase
{
    public function testOk(): void
    {
        self::assertSame({$i}, {$i});
    }
}
PHP;
        file_put_contents($mwDir . "/src/{$className}.php", $body);
    }

    $resultsByWorkers = [];
    foreach ([1, 4] as $workers) {
        $toml = <<<TOML
version = "1"
php-version = "8.5"

[source]
paths = ["src"]
includes = ["{$root}/vendor/phpunit/phpunit/src"]

[analyzer]
find-unused-definitions = false
find-unused-expressions = false
check-property-initialization = true

[extension-hosts.phpunit]
command = ["php", "{$root}/bin/mago-phpunit-worker"]
workers = {$workers}
TOML;
        file_put_contents($mwDir . '/mago.toml', $toml);
        $issues = runAnalyzeJson($magoCommand, $mwDir . '/mago.toml', $mwDir, false);
        $normalized = array_map(
            static fn (array $issue): string => sprintf('%s:%d:%s:%s', $issue['file'], $issue['line'], $issue['code'], $issue['message']),
            $issues,
        );
        sort($normalized);
        $resultsByWorkers[$workers] = $normalized;
    }

    $providerIssues1 = array_values(array_filter(
        $resultsByWorkers[1],
        static fn (string $entry): bool => str_contains($entry, 'alfredbez/phpunit/phpunit-data-provider'),
    ));
    if (count($providerIssues1) !== 2) {
        fwrite(STDERR, 'FAIL multi-worker: expected 2 cross-file phpunit-data-provider diagnostics for workers=1, got ' . count($providerIssues1) . "\n");
        $failures++;
    }
    if ($resultsByWorkers[1] !== $resultsByWorkers[4]) {
        fwrite(STDERR, "FAIL multi-worker: workers=1 and workers=4 diagnostic sets diverged\n");
        fwrite(STDERR, '  workers=1: ' . json_encode($resultsByWorkers[1]) . "\n");
        fwrite(STDERR, '  workers=4: ' . json_encode($resultsByWorkers[4]) . "\n");
        $failures++;
    }

    // Also verify the repository's own tests/fixtures under workers=1 vs workers=4.
    $repoByWorkers = [];
    foreach ([1, 4] as $workers) {
        $tomlPath = $mwDir . "/repo-w{$workers}.toml";
        $toml = <<<TOML
version = "1"
php-version = "8.5"

[source]
paths = ["tests/fixtures"]
includes = ["vendor/phpunit/phpunit/src", "vendor/phpspec/prophecy/src", "vendor/phpspec/prophecy-phpunit/src"]

[analyzer]
find-unused-definitions = false
find-unused-expressions = false
check-property-initialization = true

[extension-hosts.phpunit]
command = ["php", "{$root}/bin/mago-phpunit-worker"]
workers = {$workers}
TOML;
        file_put_contents($tomlPath, $toml);
        $issues = runAnalyzeJson($magoCommand, $tomlPath, $root, false);
        $normalized = array_map(
            static fn (array $issue): string => sprintf('%s:%d:%s:%s', $issue['file'], $issue['line'], $issue['code'], $issue['message']),
            $issues,
        );
        sort($normalized);
        $repoByWorkers[$workers] = $normalized;
    }
    if ($repoByWorkers[1] !== $repoByWorkers[4]) {
        fwrite(STDERR, "FAIL multi-worker: repo tests/fixtures diverged between workers=1 and workers=4\n");
        $failures++;
    }

    // 2. Back-to-back mutated provider fixture passes (verifies no stale provider rows leak).
    $mutToml = $mwDir . '/mago.toml';
    file_put_contents($mutToml, <<<TOML
version = "1"
php-version = "8.5"

[source]
paths = ["src/SharedExternalProvider.php", "src/SiblingExternalProvider11Test.php"]
includes = ["{$root}/vendor/phpunit/phpunit/src"]

[analyzer]
find-unused-definitions = false
find-unused-expressions = false

[extension-hosts.phpunit]
command = ["php", "{$root}/bin/mago-phpunit-worker"]
workers = 1
TOML);
    $pass1 = array_values(array_filter(
        runAnalyzeJson($magoCommand, $mutToml, $mwDir, false),
        static fn (array $i): bool => $i['code'] === 'alfredbez/phpunit/phpunit-data-provider',
    ));
    if (count($pass1) !== 2) {
        fwrite(STDERR, 'FAIL back-to-back pass 1: expected 2 data-provider errors, got ' . count($pass1) . "\n");
        $failures++;
    }

    file_put_contents($mwDir . '/src/SharedExternalProvider.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MultiWorkerFixtures;

final class SharedExternalProvider
{
    public static function goodRows(): array
    {
        return [[1, 'alpha']];
    }

    public static function brokenRows(): array
    {
        return [[42, 'now-valid']];
    }
}
PHP);
    $pass2 = array_values(array_filter(
        runAnalyzeJson($magoCommand, $mutToml, $mwDir, false),
        static fn (array $i): bool => $i['code'] === 'alfredbez/phpunit/phpunit-data-provider',
    ));
    if (count($pass2) !== 0) {
        fwrite(STDERR, 'FAIL back-to-back pass 2: expected 0 data-provider errors after mutation, got ' . count($pass2) . "\n");
        $failures++;
    }

    // In-process verification of BeforeAnalysisHook cache clearing on a long-lived plugin instance.
    $registry = new PluginRegistry();
    $dataProviders = new DataProviderArguments();
    (new PhpUnitPlugin($dataProviders))->register($registry);
    $beforeHooks = $registry->getBeforeAnalysisHooks();
    if ($beforeHooks === []) {
        fwrite(STDERR, "FAIL cache-reset: PhpUnitPlugin did not register a BeforeAnalysisHook\n");
        $failures++;
    } else {
        $legacyIndex = null;
        foreach ($registry->getIssueFilterHooks() as $filter) {
            if ($filter instanceof \AlfredBez\MagoPhpunit\Analyzer\Providers\LegacyMockIssueFilter) {
                $ref = new ReflectionProperty($filter, 'sourceIndex');
                $legacyIndex = $ref->getValue($filter);
            }
        }
        if ($legacyIndex instanceof LegacyMockSourceIndex) {
            $sample = "<?php\nclass A { public function f(\$x) { \$x->method('a'); } }\n";
            $legacyIndex->find('A.php', $sample, new Span(42, 48), new Span(38, 40));
            $cacheProp = new ReflectionProperty($legacyIndex, 'cache');
            if (count($cacheProp->getValue($legacyIndex)) !== 1) {
                fwrite(STDERR, "FAIL cache-reset: expected LegacyMockSourceIndex cache to be populated before reset\n");
                $failures++;
            }
            $cancellation = new SignalCancellationToken();
            $host = (new ReflectionClass(HostClient::class))->newInstanceWithoutConstructor();
            $metadata = new MetadataCache(1);
            $beforeCtx = new BeforeAnalysisContext(
                PHPVersion::fromParts(8, 3),
                new Codebase($host, 1, $cancellation, $metadata),
                new TypeComparator($host, 1, $cancellation, $metadata),
                $cancellation,
            );
            foreach ($beforeHooks as $hook) {
                $hook->beforeAnalysis($beforeCtx);
            }
            if ($cacheProp->getValue($legacyIndex) !== []) {
                fwrite(STDERR, "FAIL cache-reset: LegacyMockSourceIndex cache was not cleared by BeforeAnalysisHook\n");
                $failures++;
            }
        } else {
            fwrite(STDERR, "FAIL cache-reset: could not locate LegacyMockSourceIndex on LegacyMockIssueFilter\n");
            $failures++;
        }
    }

    // 3. Lifecycle entry points under find-unused-definitions = true.
    $epDir = $mwDir . '/entrypoints';
    mkdir($epDir, 0777, true);
    file_put_contents($epDir . '/LifecycleEntryPointsTest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace EntryPointFixtures;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\PostCondition;
use PHPUnit\Framework\Attributes\PreCondition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LifecycleEntryPointsTest extends TestCase
{
    #[BeforeClass]
    private static function initSuite(): void {}

    #[AfterClass]
    private static function shutdownSuite(): void {}

    #[Before]
    protected function prepareFixture(): void {}

    #[After]
    private function cleanupFixture(): void {}

    #[PreCondition]
    protected function verifyBefore(): void {}

    #[PostCondition]
    protected function verifyAfter(): void {}

    protected function setUp(): void {}

    protected function tearDown(): void {}

    public static function setUpBeforeClass(): void {}

    public static function tearDownAfterClass(): void {}

    protected function assertPreConditions(): void {}

    protected function assertPostConditions(): void {}

    #[Test]
    public function verifiesBehavior(): void
    {
        self::assertTrue(true);
    }

    private function genuinelyUnusedHelper(): void {}
}

final class NonTestClass
{
    #[Before]
    private function ignoredAttributeOnPlainClass(): void {}
}
PHP);

    file_put_contents($epDir . '/mago.toml', <<<TOML
version = "1"
php-version = "8.5"

[source]
paths = ["LifecycleEntryPointsTest.php"]
includes = ["{$root}/vendor/phpunit/phpunit/src"]

[analyzer]
find-unused-definitions = true
find-unused-expressions = false

[extension-hosts.phpunit]
command = ["php", "{$root}/bin/mago-phpunit-worker"]
workers = 1
TOML);

    $epWith = array_values(array_filter(
        runAnalyzeJson($magoCommand, $epDir . '/mago.toml', $epDir, false),
        static fn (array $i): bool => $i['code'] === 'unused-method',
    ));
    $epWithout = array_values(array_filter(
        runAnalyzeJson($magoCommand, $epDir . '/mago.toml', $epDir, true),
        static fn (array $i): bool => $i['code'] === 'unused-method',
    ));

    $unusedNamesWith = [];
    foreach ($epWith as $i) {
        if (preg_match('/Method `([^`]+)\(\)`/', $i['message'], $m) === 1) {
            $unusedNamesWith[] = $m[1];
        }
    }
    sort($unusedNamesWith);
    $expectedUnused = ['genuinelyUnusedHelper', 'ignoredAttributeOnPlainClass'];
    if ($unusedNamesWith !== $expectedUnused) {
        fwrite(STDERR, 'FAIL entry-points with extension: expected unused-method=' . json_encode($expectedUnused) . ', got ' . json_encode($unusedNamesWith) . "\n");
        $failures++;
    }
    if (count($epWithout) <= count($epWith)) {
        fwrite(STDERR, 'FAIL entry-points control: expected --no-extensions to report more unused-method diagnostics than with extension (' . count($epWithout) . ' vs ' . count($epWith) . ")\n");
        $failures++;
    }
} finally {
    removeDir($mwDir);
}

echo "worker-and-lifecycle: {$failures} failures\n";
exit($failures === 0 ? 0 : 1);

/**
 * @param list<string> $magoCommand
 * @return list<array{level: string, code: string, file: string, line: int, message: string}>
 */
function runAnalyzeJson(array $magoCommand, string $tomlPath, string $cwd, bool $noExtensions): array
{
    $cmd = [...$magoCommand, '--config', $tomlPath, '--workspace', $cwd, 'analyze', '--reporting-format=json'];
    if ($noExtensions) {
        $cmd[] = '--no-extensions';
    }
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if ($process === false) {
        throw new RuntimeException('Failed to start Mago');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit > 1 || str_contains($stderr, 'External plugin error') || str_contains($stderr, 'worker failed')) {
        throw new RuntimeException("Mago failed (exit {$exit}): {$stderr}\n{$stdout}");
    }
    $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !isset($decoded['issues']) || !is_array($decoded['issues'])) {
        throw new RuntimeException("Invalid Mago JSON: {$stdout}");
    }
    $issues = [];
    foreach ($decoded['issues'] as $issue) {
        $primary = $issue['annotations'][0];
        foreach ($issue['annotations'] as $ann) {
            if (($ann['kind'] ?? '') === 'Primary') {
                $primary = $ann;
                break;
            }
        }
        $start = $primary['span']['start'];
        $line = array_key_exists('column', $start) ? (int) $start['line'] : (int) $start['line'] + 1;
        $issues[] = [
            'level' => (string) $issue['level'],
            'code' => (string) $issue['code'],
            'file' => (string) $primary['span']['file_id']['name'],
            'line' => $line,
            'message' => (string) $issue['message'],
        ];
    }

    return $issues;
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}
