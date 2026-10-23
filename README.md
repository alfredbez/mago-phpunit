# alfredbez/mago-phpunit

PHPUnit support for [Mago](https://github.com/carthage-software/mago). The package is an external analyzer extension: it teaches Mago about PHPUnit assertions and the test lifecycle. It does not change PHPUnit itself.

This first version does three things:

- After `assertArrayHasKey()` with a literal string or integer key, Mago treats that key as present on the array argument. Calls may go through `$this`, `self::`, `static::`, or `PHPUnit\Framework\Assert::`. A non-literal key is not narrowed, and a different key stays uncertain.
- `assertContainsOnlyInstancesOf(Foo::class, $items)` treats `$items` as an iterable of `Foo` when the class name is a literal class string. PHPUnit 12 already declares this with `@phpstan-assert`, and Mago honors that without an extension. PHPUnit 10.5 does not, so the extension supplies the same fact. A non-literal class name is left unchanged.
- For subclasses of `PHPUnit\Framework\TestCase`, `setUp()` counts as a constructor when Mago checks property initialization. That includes `setUp()` inherited from a parent test class, and methods called from `setUp()`. A property that `setUp()` never assigns is still reported. `setUp()` on a class that does not extend `TestCase` is left alone.

The extension follows Mago's worker model. This package exposes `AlfredBez\MagoPhpunit\PhpUnitExtension::create()`. The application owns the worker entrypoint.

## Install

```shell
composer require --dev alfredbez/mago-phpunit
```

`carthage-software/mago` 1.58.2, or a later 1.58 release, is installed with it. The extension is developed against that SDK. PHPUnit stays your application's dependency; this package does not require a PHPUnit version.

## Configure

Create `.mago/worker.php`:

```php
<?php

declare(strict_types=1);

use AlfredBez\MagoPhpunit\PhpUnitExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Worker(PhpUnitExtension::create()))->run();
```

Register the host in `mago.toml`. Several extension factories can be passed to the same `Worker` when one project loads more than one extension.

```toml
[extension-hosts.phpunit]
command = ["php", ".mago/worker.php"]
```

`php` is resolved from `PATH`. The table name `phpunit` is only the local host name. Standard output belongs to the worker protocol.

Optional keys in array shapes are already reported. `assertArrayHasKey()` clears that report for a literal key.

Property initialization is off unless you enable it. `setUp()` only affects that check:

```toml
[analyzer]
check-property-initialization = true
```

You do not need `class-initializers = ["setUp"]`. That setting would treat every `setUp()` method as a constructor. This extension only does that for `TestCase` subclasses.

Check that the host starts:

```shell
mago extension validate
mago analyze
```

Run `mago analyze --no-extensions` to see the same project without the extension.

## Development

```shell
composer install
composer test
```

`composer test` runs Mago on the fixtures in `tests/fixtures` twice: once with the extension and once with `--no-extensions`. The assertions compare issue codes and counts in that output. GitHub Actions runs the same command on PHP 8.3 against PHPUnit 12 and PHPUnit 10.5.

## Limits

- Only literal string and integer keys are narrowed. A variable, expression, or class-constant key contributes no fact.
- `assertContainsOnlyInstancesOf()` only narrows a literal class string such as `Foo::class`.
- `assertArrayNotHasKey()` and other assertions are not covered yet.
- Mago's initializer analysis treats a property as initialized when `setUp()` assigns it on a reachable path. An assignment behind a condition that Mago cannot fold can therefore suppress `uninitialized-property` even though another path leaves the property unset. A property that `setUp()` never assigns is still reported.

## License

MIT. See [LICENSE](LICENSE).
