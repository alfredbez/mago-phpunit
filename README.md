# alfredbez/mago-phpunit

PHPUnit support for [Mago](https://github.com/carthage-software/mago). This package is an external analyzer extension that teaches Mago about PHPUnit assertions, `TestCase` property initialization, and data providers. It does not modify PHPUnit.

## Features

The extension registers five hooks under the `alfredbez/phpunit` plugin:

- `assertArrayHasKey()` narrows the array argument when the key is a literal string or integer. Calls may go through `$this`, `self::`, `static::`, `PHPUnit\Framework\Assert::`, or the function `PHPUnit\Framework\assertArrayHasKey()`. A non-literal key is not narrowed, other keys stay uncertain, and nullable values remain nullable. Named-argument warnings from PHPUnit's compatibility annotations are preserved.
- `assertContainsOnlyInstancesOf(Foo::class, $items)` narrows `$items` to `iterable<mixed, Foo>` when the first argument is a literal class string and the installed `Assert::assertContainsOnlyInstancesOf()` carries no assertion of its own for `$haystack`. PHPUnit 11.5 and later already declare `@phpstan-assert` on that method, so this hook activates only on PHPUnit 10.5. A non-literal class name is left unchanged.
- `setUp()` counts as a class initializer on subclasses of `PHPUnit\Framework\TestCase` when Mago checks property initialization. That includes `setUp()` inherited from a parent test class and helper methods called from `setUp()`. A property that `setUp()` never assigns is still reported, and `setUp()` on a class that does not extend `TestCase` is left alone.
- Data providers on `TestCase` subclasses are checked and reported at error level as `alfredbez/phpunit/phpunit-data-provider`:
  - The hook reads `#[DataProvider]`, `#[DataProviderExternal]`, `#[DataProviderClosure]`, and `@dataProvider`. PHPUnit 12 removed doc-comment metadata, so `@dataProvider` is checked only when `PHPUnit\Metadata\Parser\AnnotationParser` exists in the analyzed codebase, which covers PHPUnit 10.5 and 11.5.
  - Each row is checked against the test method signature for positional argument count, variadic parameters, missing required or unknown named arguments, yielded non-array values, and element types when both the parameter type and the row element type are concrete. Passing `false` for `validateArgumentCount`, positionally or by name, suppresses only the extra-argument check.
  - Provider bodies may return an array literal, return a local variable assigned a literal array once, `yield` rows, or `yield from` a literal array. Method providers are resolved on the test class, a parent test class in the same file, or an external class in the same file. Repeatable provider attributes and mixing `#[DataProvider]` with `#[DataProviderClosure]` on the same test method work.
  - `#[DataProviderClosure]`, introduced in PHPUnit 13.1.0 in PHPUnit `#6526`, is checked only when `PHPUnit\Framework\Attributes\DataProviderClosure` exists in the analyzed codebase. The hook checks static closures without `use` whose body is a literal `return`, `yield`, or `yield from` of a literal array. Non-literal bodies and closures with `use` stay silent.

`assertInstanceOf()`, `assertNull()`, and `assertSame()` are narrowed natively by Mago without an extension. PHPUnit 10.5 annotates them with `@psalm-assert`, and PHPUnit 11.5 and later annotate them with `@phpstan-assert`.

## Supported versions

- PHP `~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0 || ~8.6.0`, as declared in `composer.json`.
- Mago `carthage-software/mago` `~1.58.2`.
- PHPUnit `^10.5 || ^11.5 || ^12.0 || ^13.0`. This package does not require `phpunit/phpunit` at runtime so your application keeps its own PHPUnit constraint. CI runs the test suite against PHPUnit `^10.5`, `^11.5`, and `^12.0` on PHP 8.3, and against `^13.0` on PHP 8.4.
- `#[DataProviderClosure]` requires PHPUnit 13.1.0 or later, PHP 8.5 at runtime for closures in constant expressions, and `php-version = "8.5"` in `mago.toml`.

## Install and usage

`alfredbez/mago-phpunit` is not published on Packagist. There is no tagged release yet, so register the GitHub repository first and require `dev-main`:

```shell
composer config repositories.mago-phpunit vcs https://github.com/alfredbez/mago-phpunit
composer require --dev alfredbez/mago-phpunit:dev-main
```

The extension follows Mago's worker model. This package exposes `AlfredBez\MagoPhpunit\PhpUnitExtension::create()`, and the application owns the worker entrypoint. Create `.mago/worker.php`:

```php
<?php

declare(strict_types=1);

use AlfredBez\MagoPhpunit\PhpUnitExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Worker(PhpUnitExtension::create()))->run();
```

Several `Extension` instances can be passed to `new Worker(...)` when one project loads more than one extension.

Register the worker host in `mago.toml`:

```toml
[extension-hosts.phpunit]
command = ["php", ".mago/worker.php"]
```

`php` is resolved from `PATH`. The table name `phpunit` is only the local host name. Standard output belongs to the worker protocol.

Property initialization checking is off in Mago unless enabled. Add it to `mago.toml` for `setUp()` initialization checks:

```toml
[analyzer]
check-property-initialization = true
```

You do not need `class-initializers = ["setUp"]`. That setting treats every `setUp()` method as a constructor, whereas this extension restricts it to `TestCase` subclasses.

When analyzing tests that use `#[DataProviderClosure]`, set the target PHP version in `mago.toml` so Mago permits static closures in attribute arguments:

```toml
php-version = "8.5"
```

Validate the extension host and run the analyzer:

```shell
vendor/bin/mago extension validate
vendor/bin/mago analyze
```

Run `vendor/bin/mago analyze --no-extensions` to analyze the same project without extensions.

## Known gaps

- `assertArrayHasKey()` ignores non-literal keys such as variables, expressions, and class constants.
- `assertContainsOnlyInstancesOf()` ignores non-literal class names.
- `assertArrayNotHasKey()` and other PHPUnit assertions that lack `@psalm-assert` or `@phpstan-assert` annotations are not covered.
- Mago treats a property as initialized when `setUp()` assigns it on a reachable path. An assignment behind a condition that Mago cannot fold can suppress `uninitialized-property` even when another path leaves the property unset.
- Provider methods in other files are not checked. Method lookup inspects only the current file.
- Non-literal provider bodies stay silent. Lists built with `$rows[] = ...`, calls to helper methods or generators, non-literal `yield from` expressions, open row shapes, `return` or `yield` inside nested callables or anonymous classes, closures with `use`, and non-literal attribute arguments are skipped.
- Arrow functions are not allowed in constant expressions in PHP 8.5 and are not checked in `#[DataProviderClosure]`.
- Element types on rows are checked only when both the test parameter type and the row element type are known and neither is `mixed`.
- Passing `validateArgumentCount` to `#[DataProvider]` or `#[DataProviderExternal]` on PHPUnit 10.5 or 11.5 triggers Mago's `too-many-arguments` because that parameter was added in PHPUnit 12. Passing named arguments to PHPUnit attributes triggers Mago's `named-argument-not-allowed` because PHPUnit annotates those attribute classes with `@no-named-arguments`.

## Tests

Install dependencies and run the fixture suite:

```shell
composer install
composer test
```

`composer test` runs `php tests/run.php`. That script validates the extension host and runs `vendor/bin/mago analyze` on `tests/fixtures` twice, once with the extension and once with `--no-extensions`, then checks exact issue codes, counts, and messages.

To run the suite against a specific PHPUnit constraint from the version matrix, update `phpunit/phpunit` and run `composer test`:

```shell
composer update --no-interaction --prefer-dist --no-progress --with="phpunit/phpunit:^12.0"
composer test
```

GitHub Actions runs that sequence for `^10.5`, `^11.5`, and `^12.0` on PHP 8.3, and for `^13.0` on PHP 8.4.

## License

MIT. See [LICENSE](LICENSE).
