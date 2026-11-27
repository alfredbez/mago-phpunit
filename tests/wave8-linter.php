<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

use AlfredBez\MagoPhpunit\PhpUnitExtension;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Linter\Rule;

$mago = getenv('MAGO_BINARY') ?: $root . '/vendor/bin/mago';
$directory = sys_get_temp_dir() . '/mago-phpunit-linter-' . bin2hex(random_bytes(8));
mkdir($directory);
$failures = [];
$prefix = 'alfredbez/phpunit/';

function linterCommand(array $command, string $directory): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Mago.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

function linterCheck(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, $message . "\n");
    }
}

function linterRun(array $arguments = []): array
{
    global $directory, $mago, $prefix;
    $result = linterCommand([
        $mago,
        '--config',
        $directory . '/mago.toml',
        'lint',
        '--reporting-format=json',
        ...$arguments,
    ], $directory);
    linterCheck($result['exit'] === 0, 'Lint should return zero for warnings: ' . $result['stderr']);
    $json = json_decode($result['stdout'], true);
    linterCheck(
        is_array($json) && isset($json['issues']),
        'Lint must return JSON issues: ' . $result['stdout'] . $result['stderr'],
    );
    return array_values(array_filter($json['issues'] ?? [], static fn(array $issue): bool => str_starts_with(
        $issue['code'] ?? '',
        $prefix,
    )));
}

function linterCounts(array $issues): array
{
    $counts = [];
    foreach ($issues as $issue) {
        $code = substr($issue['code'], strlen('alfredbez/phpunit/'));
        $counts[$code] = ($counts[$code] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
}

function linterReset(): void
{
    global $root, $directory;
    foreach (['Assertions', 'Providers', 'Negative'] as $fixture) {
        copy($root . '/tests/linter/' . $fixture . '.php', $directory . '/' . $fixture . '.php');
    }
}

function linterFix(string $rule, bool $unsafe = false): string
{
    global $mago, $directory, $prefix;
    $result = linterCommand([
        $mago,
        '--config',
        $directory . '/mago.toml',
        'lint',
        '--only',
        $prefix . $rule,
        '--fix',
        ...($unsafe ? ['--potentially-unsafe'] : []),
    ], $directory);
    linterCheck($result['exit'] === 0, 'Fix should complete: ' . $result['stdout'] . $result['stderr']);
    foreach (glob($directory . '/*.php') ?: [] as $path) {
        $syntax = linterCommand([PHP_BINARY, '-l', $path], $directory);
        linterCheck($syntax['exit'] === 0, 'A fix produced invalid PHP: ' . $syntax['stdout'] . $syntax['stderr']);
    }
    return file_get_contents($directory . '/Assertions.php');
}

try {
    $tomlString = static fn(string $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents(
        $directory . '/mago.toml',
        "version = \"1\"\n[source]\npaths = [\".\"]\n[linter]\nintegrations = []\n[extension-hosts.phpunit]\ncommand = ["
        . $tomlString(PHP_BINARY)
        . ', '
        . $tomlString($root . '/bin/mago-phpunit-worker')
        . "]\n",
    );
    linterReset();
    linterCheck(PhpUnitExtension::plugin() instanceof Plugin, 'plugin() must return an Analyzer Plugin.');
    $rules = PhpUnitExtension::linterRules();
    linterCheck(
        count($rules) === 8 && count(array_filter($rules, static fn($rule): bool => $rule instanceof Rule)) === 8,
        'linterRules() must return all eight SDK rules.',
    );
    $enabled = [];
    foreach ($rules as $rule) {
        $definition = $rule->getDefinition();
        if ($definition->defaultEnabled) {
            $enabled[] = $definition->code;
        }
    }
    linterCheck(
        $enabled === [
            $prefix . 'strict-assertions',
            $prefix . 'use-specific-assertions',
            $prefix . 'assertion-style-static',
        ],
        'Exactly the three documented rules must be enabled by default.',
    );
    $defaults = linterRun();
    $expected = ['assertion-style-static' => 3, 'strict-assertions' => 4, 'use-specific-assertions' => 10];
    linterCheck(
        linterCounts($defaults) === $expected,
        'Default diagnostics differ: ' . json_encode(linterCounts($defaults)),
    );
    foreach ($defaults as $issue) {
        linterCheck($issue['level'] === 'Warning', 'All extension linter rules must report warnings.');
    }
    linterCheck(linterRun(['--no-extensions']) === [], 'Disabled extension must remove all custom reports.');
    $baseConfig = file_get_contents($directory . '/mago.toml');
    file_put_contents(
        $directory . '/mago.toml',
        $baseConfig
        . "\n[linter.rules.\"{$prefix}assertion-style-static\"]\nenabled = false\n[linter.rules.\"{$prefix}assertion-style-self\"]\nenabled = true\n",
    );
    linterCheck(
        linterCounts(linterRun([$directory . '/Assertions.php'])) === [
            'assertion-style-self' => 14,
            'strict-assertions' => 4,
            'use-specific-assertions' => 10,
        ],
        'TOML must disable static style and activate self style.',
    );
    file_put_contents($directory . '/mago.toml', $baseConfig);

    linterCheck(linterRun([
        $directory . '/Negative.php',
        '--only',
        implode(',', array_map(
            static fn(Rule $r): string => $r->getDefinition()->code,
            array_filter(
                $rules,
                static fn(Rule $r): bool => !in_array(
                    $r->getDefinition()->code,
                    [$prefix . 'assertion-style-self', $prefix . 'assertion-style-this'],
                    true,
                ),
            ),
        )),
    ]) === [], 'Unrelated receivers, dynamic names and already-specific assertions must stay silent.');
    foreach ([
        'assertion-style-self' => 14,
        'assertion-style-this' => 14,
        'prefer-test-attribute' => 7,
        'no-assert-empty' => 2,
        'data-provider-name-case' => 2,
    ] as $rule => $count) {
        linterCheck(
            linterCounts(linterRun([
                '--only',
                $prefix . $rule,
                ...(str_starts_with($rule, 'assertion-style-') ? [$directory . '/Assertions.php'] : []),
            ])) === [$rule => $count],
            'Opt-in diagnostic count differs for ' . $rule,
        );
    }
    $safe = linterFix('use-specific-assertions');
    linterCheck(
        str_contains($safe, "static::assertNull(\$value, 'keep the message')"),
        'Safe specific fix must retain the assertion message.',
    );
    linterCheck(
        str_contains($safe, 'static::assertTrue($value)')
        && str_contains($safe, 'static::assertFalse($value)')
        && str_contains($safe, 'static::assertNotNull($value)'),
        'Strict boolean/null assertions must receive safe fixes.',
    );
    linterCheck(
        str_contains($safe, 'static::assertEquals(true, $value)')
        && str_contains($safe, 'static::assertEquals(null, $value)'),
        'Loose assertions must not receive safe fixes.',
    );
    linterCheck(
        str_contains($safe, 'static::assertSame(actual: $value, expected: true)')
        && str_contains($safe, 'static::assertSame(null, ...$arguments)'),
        'Named and unpacked arguments must not receive specific fixes.',
    );
    linterCheck(str_contains($safe, '/* keep this comment */'), 'Specific fixes must preserve comments.');
    linterCheck(
        str_contains($safe, 'static::assertNull(nestedNamed(value: $value))'),
        'Nested named arguments must not block an outer positional fix.',
    );
    linterReset();
    $unsafeSpecific = linterFix('use-specific-assertions', true);
    linterCheck(
        str_contains($unsafeSpecific, 'static::assertTrue($value)')
        && !str_contains($unsafeSpecific, 'static::assertEquals(true, $value)'),
        'Potentially unsafe mode must apply loose specific fixes.',
    );
    linterReset();
    $safeStrict = linterFix('strict-assertions');
    linterCheck(
        $safeStrict === file_get_contents($root . '/tests/linter/Assertions.php'),
        'Strict-comparison fixes must require potentially unsafe mode.',
    );
    $strict = linterFix('strict-assertions', true);
    linterCheck(
        str_contains($strict, '$this->assertSame(42, $value)')
        && str_contains($strict, "self::assertNotSame('x', \$value)"),
        'Strict fixes must rename both equality assertions.',
    );
    linterCheck(
        str_contains($strict, '\\PHPUnit\\Framework\\assertSame(2, $value)'),
        'An imported function alias must become an unambiguous PHPUnit function.',
    );
    linterReset();
    $safeStyle = linterFix('assertion-style-static');
    linterCheck(
        $safeStyle === file_get_contents($root . '/tests/linter/Assertions.php'),
        'Style fixes must require potentially unsafe mode.',
    );
    $static = linterFix('assertion-style-static', true);
    linterCheck(
        str_contains($static, 'static::assertEquals(42, $value)')
        && str_contains($static, "static::assertNotEquals('x', \$value)"),
        'Static style fixes must handle both instance and self calls.',
    );
    linterReset();
    $self = linterFix('assertion-style-self', true);
    linterCheck(
        str_contains($self, 'self::assertEquals(42, $value)') && str_contains($self, 'self::assertEmpty($value)'),
        'Self style fixes must handle both instance and static calls.',
    );
    linterReset();
    $thisStyle = linterFix('assertion-style-this', true);
    linterCheck(str_contains($thisStyle, '$this->assertEmpty($value)'), 'This style fixes must use instance calls.');
    linterCheck(
        str_contains($thisStyle, 'self::assertTrue($value)'),
        'This style must not introduce $this into a static method.',
    );

    copy($root . '/tests/linter/StaticAndAbstract.php', $directory . '/StaticAndAbstract.php');
    linterCheck(
        linterCounts(linterRun(['--only', $prefix . 'assertion-style-this', $directory . '/StaticAndAbstract.php'])) === ['assertion-style-this' => 3],
        'assertion-style-this must skip static methods, static closures, static arrow functions, and closures inside static methods.',
    );
    linterCheck(
        linterCounts(linterRun(['--only', $prefix . 'prefer-test-attribute', $directory . '/StaticAndAbstract.php'])) === ['prefer-test-attribute' => 1],
        'prefer-test-attribute must skip abstract test methods and their concrete overrides while still reporting standalone concrete ones.',
    );
    linterFix('assertion-style-this', true);
    $fixedStaticAndAbstract = file_get_contents($directory . '/StaticAndAbstract.php');
    linterCheck(
        substr_count($fixedStaticAndAbstract, '$this->assertTrue($value)') === 3
        && substr_count($fixedStaticAndAbstract, 'self::assertTrue($value)') === 2
        && substr_count($fixedStaticAndAbstract, 'static::assertTrue($value)') === 2,
        'assertion-style-this autofix must rewrite only bound instance calls and preserve all static-scope calls.',
    );
    linterCheck(
        linterRun(['--only', $prefix . 'assertion-style-this', $directory . '/StaticAndAbstract.php']) === [],
        'assertion-style-this must report zero remaining issues after autofix on StaticAndAbstract.',
    );
    copy($root . '/tests/linter/StaticAndAbstract.php', $directory . '/StaticAndAbstract.php');
    linterFix('prefer-test-attribute', true);
    $fixedAttributeFile = file_get_contents($directory . '/StaticAndAbstract.php');
    linterCheck(
        str_contains($fixedAttributeFile, 'abstract public function testAbstractContract(): void;')
        && str_contains($fixedAttributeFile, 'public function testAbstractContract(): void')
        && str_contains($fixedAttributeFile, 'public function instanceScopes(bool $value): void'),
        'prefer-test-attribute autofix must preserve abstract test methods and concrete overrides while renaming standalone concrete tests.',
    );
    $loadCheck = linterCommand([
        PHP_BINARY,
        '-r',
        'require ' . var_export($root . '/vendor/autoload.php', true)
        . '; require ' . var_export($directory . '/StaticAndAbstract.php', true)
        . '; echo (new ReflectionClass("Wave8Linter\\\\StaticAndAbstract"))->isInstantiable() ? "OK" : "FAIL";',
    ], $directory);
    linterCheck(
        $loadCheck['exit'] === 0 && trim($loadCheck['stdout']) === 'OK',
        'prefer-test-attribute autofix must keep concrete subclasses of abstract test classes instantiable: ' . $loadCheck['stdout'] . $loadCheck['stderr'],
    );
    $listTests = linterCommand([
        PHP_BINARY,
        $root . '/vendor/bin/phpunit',
        '--list-tests',
        $directory . '/StaticAndAbstract.php',
    ], $directory);
    linterCheck(
        $listTests['exit'] === 0
        && str_contains($listTests['stdout'], 'StaticAndAbstract::testAbstractContract')
        && str_contains($listTests['stdout'], 'StaticAndAbstract::instanceScopes'),
        'PHPUnit must still discover both the concrete override and the renamed #[Test] method after autofix: ' . $listTests['stdout'] . $listTests['stderr'],
    );
    unlink($directory . '/StaticAndAbstract.php');
    linterReset();
    linterFix('prefer-test-attribute', true);
    $provider = file_get_contents($directory . '/Providers.php');
    linterCheck(
        str_contains($provider, '#[\\PHPUnit\\Framework\\Attributes\\Test]')
        && str_contains($provider, 'public function attribute('),
        'Test attribute fix must add the attribute and rename the method.',
    );
    linterCheck(
        str_contains($provider, 'public function testCollision()'),
        'A colliding test method rename must not be offered.',
    );
    linterCheck(
        linterCounts(linterRun(['--only', $prefix . 'prefer-test-attribute'])) === ['prefer-test-attribute' => 3],
        'Attribute fixes must leave collisions and invalid replacement names unchanged.',
    );
    linterCheck(str_contains($provider, 'function test123()') && str_contains($provider, 'function test9Cases()'), 'Numeric method suffixes must not produce invalid PHP fixes.');
    linterReset();
    linterFix('data-provider-name-case');
    $provider = file_get_contents($directory . '/Providers.php');
    linterCheck(
        str_contains($provider, "#[Rows('myRows')]") && str_contains($provider, '@dataProvider inheritedRows'),
        'Provider fixes must use declared casing for attribute and inherited docblock providers.',
    );
    linterCheck(
        linterRun(['--only', $prefix . 'data-provider-name-case']) === [],
        'Provider fixes must be idempotent.',
    );
    linterReset();
    $empty = linterFix('no-assert-empty', true);
    linterCheck(
        $empty === file_get_contents($root . '/tests/linter/Assertions.php'),
        'No-assert-empty must not offer an autofix.',
    );
    file_put_contents(
        $directory . '/Fix.php',
        '<?php declare(strict_types=1); namespace Wave8Linter; final class Fix extends \\PHPUnit\\Framework\\TestCase { public function example(mixed $value): void { static::assertSame(null, $value); } }',
    );
    $fix = linterCommand([
        $mago,
        '--config',
        $directory . '/mago.toml',
        'fix',
        '--no-guard',
        '--no-analyze',
        '--no-fmt',
        $directory . '/Fix.php',
    ], $directory);
    linterCheck(
        $fix['exit'] === 0 && str_contains(file_get_contents($directory . '/Fix.php'), 'static::assertNull($value)'),
        'mago fix must execute extension safe edits and converge: ' . $fix['stdout'] . $fix['stderr'],
    );
    linterReset();
    $defaultFix = linterCommand([
        $mago,
        '--config',
        $directory . '/mago.toml',
        'lint',
        '--fix',
        '--potentially-unsafe',
        $directory . '/Assertions.php',
    ], $directory);
    $fixedAssertions = file_get_contents($directory . '/Assertions.php');
    linterCheck(
        $defaultFix['exit'] <= 1
        && str_contains($fixedAssertions, 'static::assertTrue($value)')
        && !str_contains($fixedAssertions, 'static::assertEquals(true, $value)')
        && !str_contains($fixedAssertions, 'static::assertSame(true, $value)')
        && str_contains($fixedAssertions, 'static::assertSame(42, $value)'),
        'Default rules must apply together without conflicting edits on assertEquals(true, $value).',
    );
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}

fwrite(STDOUT, 'Wave 8 linter behavior: ' . count($failures) . " failures\n");
exit($failures === [] ? 0 : 1);
