# alfredbez/mago-phpunit

PHPUnit support for [Mago](https://github.com/carthage-software/mago) 1.60.2 through its PHP extension SDK. This external analyzer extension teaches Mago about PHPUnit assertions, `TestCase` property initialization, data providers, coverage targets, mocks, and test dependencies.

## Supported versions

- PHP `~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0 || ~8.6.0`
- Mago (`carthage-software/mago`) `~1.60.2`
- PHPUnit `^10.5 || ^11.5 || ^12.0 || ^13.0` (not required at runtime so applications keep their own PHPUnit constraint; CI tests `^10.5`, `^11.5`, and `^12.0` on PHP 8.3, and `^13.0` on PHP 8.4)
- `#[DataProviderClosure]` requires PHPUnit 13.1.0+, PHP 8.5 at runtime, and `php-version = "8.5"` in `mago.toml`

## Installation and quickstart

Until `0.1.0` is published on Packagist, register the GitHub repository and install `dev-main`:

```shell
composer config repositories.mago-phpunit vcs https://github.com/alfredbez/mago-phpunit
composer require --dev alfredbez/mago-phpunit:dev-main
```

Once tagged on Packagist, install the package directly:

```shell
composer require --dev alfredbez/mago-phpunit:^0.1
```

Register the bundled worker binary in `mago.toml` and include PHPUnit's source directory so Mago indexes PHPUnit types:

```toml
[source]
includes = ["vendor/phpunit/phpunit/src"]

[analyzer]
check-property-initialization = true

[extension-hosts.phpunit]
command = ["php", "vendor/bin/mago-phpunit-worker"]
workers = 1
```

You do not need `class-initializers = ["setUp"]` in `mago.toml`, because this extension scopes `setUp()` initialization strictly to `TestCase` subclasses. Validate the extension host and run the analyzer:

```shell
vendor/bin/mago extension validate
vendor/bin/mago analyze
```

## Features

The extension registers hooks under the `alfredbez/phpunit` plugin:

- **`assertArrayHasKey()` and `assertArrayNotHasKey()` narrowing.** Narrows the array argument when the key is a literal string or integer (`HasKey` or `DoesNotHaveKey`). Works through `$this`, `self::`, `static::`, `PHPUnit\Framework\Assert::`, and the `PHPUnit\Framework\assertArrayHasKey()` and `assertArrayNotHasKey()` functions. Preserves non-literal uncertainty, nullable values, and PHPUnit's `@no-named-arguments` warnings.
- **`assertCount()`, `assertNotEmpty()`, string affix, `assertJson()`, integer bound, and `assertContainsOnly()` narrowing.** Narrows literal positive counts to non-empty countables, literal zero counts to empty countables, `assertNotEmpty()` on typed arrays, strings, and nullables to non-empty, `assertStringContainsString()`, `assertStringContainsStringIgnoringCase()`, `assertStringStartsWith()`, and `assertStringEndsWith()` with non-empty needles or affixes to `non-empty-string`, `assertJson()` to `non-empty-string`, `assertGreaterThan()`, `assertGreaterThanOrEqual()`, `assertLessThan()`, and `assertLessThanOrEqual()` with literal integer bounds on pure `int` targets to bounded integer ranges, and `assertContainsOnly()` with literal native or class-like types to `iterable<mixed, T>` (method and function forms).
- **`assertContainsOnlyInstancesOf()` narrowing (PHPUnit 10.5).** Narrows `$haystack` to `iterable<mixed, Foo>` when the first argument is a literal class string and the installed `Assert::assertContainsOnlyInstancesOf()` lacks `@phpstan-assert`. Activates only on PHPUnit 10.5 because PHPUnit 11.5+ already declares `@phpstan-assert`.
- **`createMockForIntersectionOfInterfaces()` and `createStubForIntersectionOfInterfaces()` return types.** Narrows literal lists of at least two distinct existing interfaces (`[Clock::class, Logger::class]`) to `Clock&Logger&MockObject` or `Clock&Logger&Stub`, and falls back to PHPUnit's declared return type for open arrays, single-element lists, duplicate interfaces, or non-interface class names.
- **`TestCase` property initialization.** Treats `setUp()` and `#[PHPUnit\Framework\Attributes\Before]` methods as class initializers on subclasses of `PHPUnit\Framework\TestCase`, including parent test classes, traits, multiple `#[Before]` hooks, and helper methods called from initializers. Missing assignments, `#[After]`, unrelated attributes named `Before`, and non-`TestCase` classes keep their diagnostics.
- **Data provider, `#[TestWith]`, and dependency validation (`alfredbez/phpunit/phpunit-data-provider`).** Supports `#[DataProvider]`, `#[DataProviderExternal]` (same-file and cross-file via `AfterAnalysisHook`), `#[DataProviderClosure]` (PHPUnit 13.1+), `#[TestWith]`, `#[TestWithJson]`, and `@dataProvider` (when `PHPUnit\Metadata\Parser\AnnotationParser` exists on PHPUnit 10.5 and 11.5). Validates provider declarations, positional arity, variadic parameters, named arguments, invalid JSON rows, concrete element types, PHPUnit 10.5 multi-key AST order, and `#[Depends]` / `#[DependsExternal]` return types.
- **Coverage target attribute validation (`alfredbez/phpunit/phpunit-invalid-covers-target`).** Checks that `#[CoversClass]`, `#[UsesClass]`, `#[CoversTrait]`, `#[UsesTrait]`, `#[CoversMethod]`, `#[UsesMethod]`, `#[CoversFunction]`, and `#[UsesFunction]` attributes on `TestCase` subclasses reference existing classes, traits, methods, or functions in the codebase.
- **Mocked method, `MockBuilder`, and `willReturn()` validation.** Validates literal method names passed to `MockObject::method()`, `Stub::method()`, `MockBuilder::onlyMethods()`, and `MockBuilder::addMethods()` (`alfredbez/phpunit/phpunit-unknown-mocked-method` and `alfredbez/phpunit/phpunit-invalid-mock-builder-method`). Validates `willReturn()` and `willReturnOnConsecutiveCalls()` argument types on fluent mock and stub chains against the mocked method's native declared return type, including `void` and `never` (`alfredbez/phpunit/phpunit-incompatible-mock-return`).

`assertInstanceOf()`, `assertNull()`, `assertSame()`, `createMock()`, and `createStub()` are narrowed natively by Mago using PHPUnit's `@psalm-assert` and `@phpstan-assert` annotations.

## Known Mago 1.60.2 SDK boundaries

- `assertObjectHasProperty()` is not implemented because Mago 1.60.2 SDK does not expose a `PropertyExists` assertion kind.
- Untyped test-method parameter inference from data providers is blocked because Mago 1.60.2 SDK does not expose a method parameter type override hook.
- `assertArrayHasKey()` and `assertArrayNotHasKey()` ignore non-literal keys, `assertContainsOnly()` and `assertContainsOnlyInstancesOf()` ignore non-literal type names, and `createMockForIntersectionOfInterfaces()` / `createStubForIntersectionOfInterfaces()` require a literal list of at least two distinct interfaces.
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
```

`composer test` runs both `@test:analyzer` (`php tests/run.php`, validating `bin/mago-phpunit-worker` and the full fixture suite) and `@test:runtime` (`vendor/bin/phpunit tests/runtime`).

Run pinned OSS corpus snapshot checks (`phpunit`, `symfony`, `shopware`, `drupal`) against `tools/corpus-baseline.json`:

```shell
php tools/corpus-run.php --check
php tools/corpus-run.php --target=phpunit,symfony --check
php tools/corpus-run.php --update-baseline
```

Run same-host local benchmarks and verify pinned conformance hashes:

```shell
php bench/run-local.php --runs=5
php spec/harvest.php
```

## License

MIT. See [LICENSE](LICENSE).
