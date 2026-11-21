# alfredbez/mago-phpunit

PHPUnit support for [Mago](https://github.com/carthage-software/mago). This package is an external analyzer extension that teaches Mago about PHPUnit assertions, `TestCase` property initialization, data providers, and test dependencies. It does not modify PHPUnit.

## Features

The extension registers hooks under the `alfredbez/phpunit` plugin:

- **`assertArrayHasKey()` narrowing.** Narrows the array argument when the key is a literal string or integer. Calls work through `$this`, `self::`, `static::`, `PHPUnit\Framework\Assert::`, or `PHPUnit\Framework\assertArrayHasKey()`. Non-literal keys are not narrowed, other keys stay uncertain, and nullable values remain nullable. Named-argument warnings from PHPUnit's `@no-named-arguments` annotations are preserved.
- **`assertContainsOnlyInstancesOf()` narrowing (PHPUnit 10.5).** Narrows `$haystack` to `iterable<mixed, Foo>` when the first argument is a literal class string and the installed `Assert::assertContainsOnlyInstancesOf()` lacks `@phpstan-assert`. PHPUnit 11.5+ already declares `@phpstan-assert`, so this hook activates only on PHPUnit 10.5.
- **`TestCase` property initialization.** Treats `setUp()` and `#[PHPUnit\Framework\Attributes\Before]` methods as class initializers on subclasses of `PHPUnit\Framework\TestCase`, including parent test classes, multiple `#[Before]` hooks, and helper methods called from initializers. Missing assignments, `#[After]`, unrelated attributes named `Before`, and non-`TestCase` classes retain their diagnostics.
- **Data provider, `#[TestWith]`, and dependency validation (`alfredbez/phpunit/phpunit-data-provider`).**
  - Supports `#[DataProvider]`, `#[DataProviderExternal]` (same-file and cross-file via `AfterAnalysisHook`), `#[DataProviderClosure]` (PHPUnit 13.1+), `#[TestWith]`, `#[TestWithJson]`, and `@dataProvider` (only when `PHPUnit\Metadata\Parser\AnnotationParser` exists, covering PHPUnit 10.5 and 11.5).
  - Checks positional argument counts, variadic parameters, missing required or unknown named arguments, invalid JSON or non-array rows, and element types whenever both parameter and row types are concrete. Passing `false` for `validateArgumentCount` suppresses only the extra-argument check.
  - On PHPUnit 10.5 (where `array_values()` strips string keys positionally), reconstructs literal multi-key array insertion order from the AST for providers, `#[TestWith]`, and `#[TestWithJson]` before comparing types; non-literal multi-key rows fall back to arity checks.
  - Validates dependency return types for `#[Depends]`, `#[DependsExternal]`, and their `UsingDeepClone` / `UsingShallowClone` variants against the corresponding trailing test parameters (standalone or combined with providers and `#[TestWith]`). Respects PHPUnit 11.5+/12+ skipping `void` and `never` dependency return values via `PassedTests::hasReturnValue()`.
  - Checks provider bodies returning an array literal, returning a local variable assigned a literal array once, `yield` rows, or `yield from` a literal array, plus repeatable provider attributes and static closures without `use` in `#[DataProviderClosure]`.

`assertInstanceOf()`, `assertNull()`, `assertSame()`, `createMock()`, and `createStub()` are narrowed natively by Mago using PHPUnit's `@psalm-assert` and `@phpstan-assert` annotations.

## Supported versions

- PHP `~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0 || ~8.6.0`
- Mago (`carthage-software/mago`) `~1.60.2`
- PHPUnit `^10.5 || ^11.5 || ^12.0 || ^13.0` (not required at runtime so applications keep their own PHPUnit constraint; CI tests `^10.5`, `^11.5`, and `^12.0` on PHP 8.3, and `^13.0` on PHP 8.4)
- `#[DataProviderClosure]` requires PHPUnit 13.1.0+, PHP 8.5 at runtime, and `php-version = "8.5"` in `mago.toml`

## Installation and worker setup

`alfredbez/mago-phpunit` is not published on Packagist yet. Register the GitHub repository and require `dev-main`:

```shell
composer config repositories.mago-phpunit vcs https://github.com/alfredbez/mago-phpunit
composer require --dev alfredbez/mago-phpunit:dev-main
```

Create `.mago/worker.php`:

```php
<?php

declare(strict_types=1);

use AlfredBez\MagoPhpunit\PhpUnitExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Worker(PhpUnitExtension::create()))->run();
```

Register the worker host in `mago.toml`:

```toml
[analyzer]
check-property-initialization = true

[extension-hosts.phpunit]
command = ["php", ".mago/worker.php"]
```

You do not need `class-initializers = ["setUp"]`, because this extension restricts `setUp()` initialization to `TestCase` subclasses. Validate the host and run the analyzer:

```shell
vendor/bin/mago extension validate
vendor/bin/mago analyze
```

## Known limits (Mago 1.60.2)

- `assertArrayHasKey()` ignores non-literal keys; `assertContainsOnlyInstancesOf()` ignores non-literal class names; `assertArrayNotHasKey()` is not covered.
- Mago treats a property as initialized when `setUp()` assigns it on a reachable path, even behind a branch Mago cannot fold.
- Non-literal provider bodies stay silent (`$rows[] = ...`, helper calls, non-literal `yield from`, open row shapes, nested callables, closures with `use`, and non-literal attribute arguments).
- Arrow functions are invalid in PHP 8.5 constant expressions and are not checked in `#[DataProviderClosure]`.
- Element and dependency types are checked only when both expected and provided types are concrete (non-`mixed`). Docblock `@depends` annotations are not parsed.
- Passing `validateArgumentCount` on PHPUnit 10.5 or 11.5 triggers Mago's `too-many-arguments` (parameter added in PHPUnit 12). Passing named arguments to PHPUnit attributes triggers `named-argument-not-allowed` due to PHPUnit's `@no-named-arguments`.

## Tests, corpus checks, and benchmarks

```shell
composer install
php tests/check-spec.php
composer test
vendor/bin/phpunit tests/runtime
```

Run pinned OSS corpus snapshot checks (`phpunit`, `symfony`, `shopware`, `drupal`) against `tools/corpus-baseline.json`:

```shell
php tools/corpus-run.php --check
php tools/corpus-run.php --target=phpunit,symfony --check
php tools/corpus-run.php --update-baseline
```

Run deterministic same-host local benchmarks (`--save` / `--compare`):

```shell
php bench/run-local.php --runs=5
```

## License

MIT. See [LICENSE](LICENSE).
