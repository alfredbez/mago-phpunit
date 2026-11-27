# alfredbez/mago-phpunit

[![tests](https://github.com/alfredbez/mago-phpunit/actions/workflows/ci.yml/badge.svg)](https://github.com/alfredbez/mago-phpunit/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/alfredbez/mago-phpunit.svg)](https://packagist.org/packages/alfredbez/mago-phpunit)
[![Downloads](https://img.shields.io/packagist/dt/alfredbez/mago-phpunit.svg)](https://packagist.org/packages/alfredbez/mago-phpunit)
[![PHP](https://img.shields.io/badge/php-8.1%20to%208.6-777BB4)](composer.json)
[![Mago](https://img.shields.io/badge/mago-%5E1.60.2-blue)](https://github.com/carthage-software/mago)
[![PHPUnit](https://img.shields.io/badge/phpunit-10.5%20to%2013.x-blue)](https://phpunit.de)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

PHPUnit support for [Mago](https://github.com/carthage-software/mago), with assertion narrowing, mock types, test checks, and lint fixes.

- Use asserted values without false mixed or nullable reports.
- Keep mock methods and mocked application methods available on the same object.
- Find broken providers, coverage targets, mock configurations, and Prophecy promises before running tests.
- Fix assertion style and common assertion choices with eight lint rules.

A misspelled mocked method is reported before the test runs:

```php
$mailer = $this->createMock(Mailer::class);
$mailer->method('sendInvioce');
// alfredbez/phpunit/phpunit-unknown-mocked-method
```

## Setup

Requires PHP 8.1 to 8.6 with the tokenizer extension, Mago `^1.60.2`, and your own PHPUnit `^10.5`, `^11.5`, `^12.0`, or `^13.0` installation.

```shell
composer require --dev alfredbez/mago-phpunit
```

Add the worker to `mago.toml`:

```toml
[source]
paths = ["tests"]
includes = ["vendor/phpunit/phpunit/src"]

[analyzer]
check-property-initialization = true

[extension-hosts.phpunit]
command = ["php", "-d", "xdebug.mode=off", "-d", "pcov.enabled=0", "-d", "memory_limit=512M", "vendor/bin/mago-phpunit-worker"]
workers = 1
```

- Include PHPUnit's source under `[source] includes` so Mago can read its types and attributes. Add your application source to `paths` or `includes` too.
- The `-d` flags keep Xdebug and PCOV out of the worker and give the worker 512M of headroom for multi-thousand-line test files. Small and medium suites also run within PHP's default 128M limit.
- Remove `"phpunit"` from `[linter] integrations` to avoid duplicate lint reports. Keep your other integrations.
- The extension recognizes `setUp()` and `#[Before]` on test classes, parent classes, and traits, and registers PHPUnit lifecycle attributes and hooks as entry points under `find-unused-definitions = true`. For readonly properties that Mago still treats as constructor-only, add `class-initializers = ["PHPUnit\\Framework\\TestCase::setUp"]` under `[analyzer]`.
- Prophecy is optional. If you use it, include `vendor/phpspec` under `[source] includes`. This package does not install Prophecy in consumers.

```shell
vendor/bin/mago extension validate
vendor/bin/mago analyze
vendor/bin/mago lint
```

## Fewer false reports

After an assertion succeeds, Mago can use the fact it proves. Method calls through `$this`, `self::`, `static::`, and `Assert::`, and PHPUnit's assertion functions share the same narrowing behavior.

| Assertion | What Mago knows afterwards |
| --- | --- |
| `assertArrayHasKey()` | A literal string or integer key exists on variable, property, or array expressions (excluding class constants, `iterable`, and `ArrayAccess` objects). Its value can still be null. |
| `assertArrayNotHasKey()` | The literal key is absent on variable, property, or array expressions (excluding class constants, `iterable`, and `ArrayAccess` objects). |
| `assertObjectHasProperty()` | An `object` or `mixed` value has the literal property. Concrete classes retain their type. |
| `assertCount()` | A literal non-negative size is known for arrays; zero is empty and positive sizes are non-empty. Normal `count()` exposes exact array sizes. |
| `assertNotCount()` | The literal size is excluded; excluding zero makes an array non-empty. |
| `assertNotSame()` | A null, bool, integer, string, or enum-case singleton is excluded. Broad expected types do not exclude their whole type. |
| `assertContains()` | The needle has the array's element type. |
| `assertNotContains()` | Literal values guaranteed present in every possible array are excluded from a literal needle union. Optional entries do not prove presence. |
| `assertEmpty()` | Known scalar and array values are empty. Values that might have object-specific emptiness semantics remain unchanged. See the PHPUnit 10 limitation below. |
| `assertNotEmpty()` | The value is non-empty when PHPUnit has no native assertion annotation. |
| `assertIsList()` | An array is a list, including PHPUnit 10.5. PHPUnit 11.5+ supplies its own annotation. |
| `assertContainsOnly()` | Elements have the literal native or class-like type. |
| `assertContainsOnlyInstancesOf()` | Elements have the supplied class type. The extension fills the PHPUnit 10.5 annotation gap. |
| String contains, starts-with, and ends-with assertions | A non-empty needle or affix proves a non-empty string. |
| `assertJson()` | The string is non-empty. |
| Integer greater-than and less-than assertions | A literal bound narrows a pure integer value to the corresponding range. |

PHPUnit's own annotations already supply `assertInstanceOf()`, `assertNull()`, `assertSame()`, and many other assertions. The extension avoids duplicating those facts.

### Mocks

Keep `MockObject` in a property's type if you configure it after assignment:

```php
/** @var Mailer&\PHPUnit\Framework\MockObject\MockObject */
private Mailer $mailer;
// Both $this->mailer->method('send') and $this->mailer->send() are available.
```

Literal interface lists passed to `createMockForIntersectionOfInterfaces()` or `createStubForIntersectionOfInterfaces()` retain every interface. The list must contain at least two distinct, existing interfaces.

Trait factories keep concrete trait methods on PHPUnit 10.5 and 11.5:

```php
$double = $this->getMockForTrait(LoggingTrait::class);
$double->method('write')->willReturn(true);
$double->log('hello'); // LoggingTrait concrete method
```

`getMockForTrait()` adds `MockObject`; `getObjectForTrait()` returns the trait type. PHPUnit 12 removed these factories.

Legacy union annotations are supported:

```php
/** @var Mailer|\PHPUnit\Framework\MockObject\MockObject $mailer */
$mailer = $this->legacyMailer();
$mailer->method('send')->willReturn(true);
```

Prefer `Mailer&MockObject` in new code. Calling `method()` or `expects()` on a plain `Mailer` in a test reports `mock-call-on-plain-type` once, with help to correct the declaration. Application classes that actually declare these methods keep their normal behavior.

```php
/** @var Mailer $mailer */
$mailer = $this->getMailer();
$mailer->method('send'); // mock-call-on-plain-type
```

### Prophecy

With `phpspec/prophecy-phpunit` installed, method setup returns `MethodProphecy`, and `reveal()` returns the doubled type:

```php
$mailer = $this->prophesize(Mailer::class);
$mailer->send(\Prophecy\Argument::type('string'))->willReturn(true);
$mailer->reveal()->send('hello');
```

`willExtend()` and `willImplement()` update the prophecy type, including standalone calls. A `Traversable` prophecy reveals an `Iterator`.

### Exceptions

Framework assertions can throw during a successful test run. With throw checking enabled, the extension filters unhandled `PHPUnit\*`, `SebastianBergmann\*`, and `Prophecy\*` exceptions in `TestCase` subclasses. Application exceptions and framework exceptions outside test classes remain visible.

```toml
[analyzer]
check-throws = true
```

## Checks

Codes in this table use the `alfredbez/phpunit/` prefix.

| Code | Level | What it reports |
| --- | --- | --- |
| `phpunit-data-provider` | error | Missing or invalid providers, malformed rows, argument or dependency type mismatches, empty local datasets, and version-specific duplicate or empty keys. |
| `phpunit-invalid-covers-target` | error | Invalid class, trait, method, function, or legacy coverage targets for the installed PHPUnit version. |
| `phpunit-unknown-mocked-method` | error | A literal mocked method is missing or cannot be configured, including final, private, constructor, and destructor methods. |
| `phpunit-invalid-mock-builder-method` | error | `addMethods()` names an already existing method. |
| `phpunit-incompatible-mock-return` | error | A fluent `willReturn()` or consecutive return does not match the mocked method's native return type. |
| `mock-call-on-plain-type` | error | A test calls the mock API on a value declared only as the application type. |
| `redundant-assertion` | warning | The static argument type already guarantees the assertion passes. |
| `assert-same-count` | warning | `assertSame()` compares an expected size with `count()` or a Countable's `count()` method. |
| `missing-parent-call` | warning | A fixture overrides a non-TestCase parent's `setUp()` or `tearDown()` without calling it. |
| `data-provider-is-test` | warning | A data provider is also discovered as a test method. |
| `mixed-data-provider` | warning | TestWith and provider metadata are combined on PHPUnit 12.5+. |
| `redundant-covers` | warning | A method repeats coverage already declared on its test class. |
| `requires-without-operator` | warning | RequiresPhp or RequiresPhpunit has no explicit comparison operator. |
| `incomplete-requires-version` | warning | A compared version omits major, minor, or patch on PHPUnit 12.5+. |
| `annotation-without-value` | warning | A PHPUnit 10 or 11 annotation omits its whitespace or required value. |
| `prophecy-union` | warning | A legacy target/ObjectProphecy union should use `ObjectProphecy<T>`. |
| `prophecy-invalid-double` | warning | Prophecy cannot double a final class, enum, trait, or the requested extension or interface target. |
| `prophecy-invalid-setup` | warning | The selected method cannot be configured by Prophecy. |
| `prophecy-unknown-type` | warning | `Argument::type()` names an unknown type. |
| `prophecy-token-mismatch` | warning | A typed token cannot match the corresponding method parameter. |
| `prophecy-invalid-return` | warning | A return, argument-return, or yield promise cannot satisfy the method's return contract. |

Ignore one code project-wide:

```toml
[analyzer]
ignore = ["alfredbez/phpunit/redundant-assertion"]
# Keep other project settings here.
```

Or ignore one expression:

```php
// @mago-ignore analysis:alfredbez/phpunit/redundant-assertion
self::assertNotNull($knownString);
// Other assertions are still checked.
```

### Prophecy calls

The checks cover typed argument tokens and `willReturn()`, `willReturnArgument()`, and `willYield()`. Void and never methods cannot return promised values. Yield promises must fit a `Generator` return. PHP's accepted scalar return coercions are preserved. Dynamic types or unresolved call chains stay conservative.

### Data providers

Providers, TestWith, TestWithJson, dependencies, inherited methods, external classes, and cross-file providers are checked. A test is empty only when every known local provider is empty. PHPUnit 13's `skipWhenEmpty: true` is honored. Duplicate dataset names, PHPUnit 11+ TestWith keys, and PHPUnit 13 empty keys follow the installed runtime contract. Docblock `@testWith` JSON is checked on PHPUnit 10.5 and 11.5 when the method has no PHPUnit attributes.

`#[DataProviderClosure]` requires PHPUnit 13.1+, PHP 8.5 at runtime, and `php-version = "8.5"`. Non-literal provider bodies, helper calls, open row shapes, and closures with captures remain conservative. Provider data does not infer undeclared test parameter types.

### Coverage

Coverage checks validate attributes and PHPUnit 10/11 `@covers` and `@coversDefaultClass` annotations against the installed runtime. Legacy user-defined functions, including `::function` without a default class, and traits are valid targets. Missing, malformed, built-in, interface, inherited-method, and leading-backslash targets are rejected where that PHPUnit version rejects them. Keep application coverage targets in Mago's source includes.

### Annotations

Requires checks recommend explicit operators and complete compared versions where PHPUnit requires them. Legacy metadata is checked only while the installed PHPUnit still supports annotations.

## Linter rules

All eight rules report warnings. Codes use the `alfredbez/phpunit/` prefix.

| Code | Default | What it reports |
| --- | --- | --- |
| `strict-assertions` | on | Scalar literal comparisons using assertEquals or assertNotEquals. Potentially unsafe fix. |
| `use-specific-assertions` | on | Null and boolean comparisons with a dedicated assertion available. Safe for strict comparisons, potentially unsafe for loose equality. |
| `assertion-style-static` | on | Instance or self assertions that can use static. Potentially unsafe fix. |
| `assertion-style-self` | off | Assertions that can use self. Potentially unsafe fix. |
| `assertion-style-this` | off | Assertions that can use `$this->` (skips static methods, static closures, and static arrow functions). Potentially unsafe fix. |
| `prefer-test-attribute` | off | Public concrete test-prefixed methods that can use the Test attribute (skips `abstract` methods and same-file overrides). Potentially unsafe rename when the new name is valid and unoccupied. |
| `no-assert-empty` | off | assertEmpty or assertNotEmpty. No fix. |
| `data-provider-name-case` | off | Provider references whose spelling differs from their declaration. Safe fix. |

To prefer `self::`, disable the default style before enabling another:

```toml
[linter.rules."alfredbez/phpunit/assertion-style-static"]
enabled = false
[linter.rules."alfredbez/phpunit/assertion-style-self"]
enabled = true
```

Named and unpacked arguments do not receive dedicated-assertion fixes. Fixes preserve messages and comments. Test renames can affect dependencies or external filters, so review potentially unsafe edits.

```shell
vendor/bin/mago lint --fix
vendor/bin/mago lint --fix --potentially-unsafe
vendor/bin/mago fix --no-guard --no-analyze --no-fmt
```

Suppress a lint report locally:

```php
// @mago-ignore lint:alfredbez/phpunit/no-assert-empty
static::assertEmpty($result);
// No other lint rules are disabled.
```

## Compared with phpstan-phpunit

[phpstan-phpunit](https://github.com/phpstan/phpstan-phpunit/blob/2.0.x/README.md) supplies assertion and mock typing, legacy mock-union support, and PHPUnit-specific rules for PHPStan. This package supplies those categories for Mago and adds the provider, coverage, Prophecy, and lint checks listed above. It does not load PHPStan configuration or reuse PHPStan's implementation. The check lists are separate contracts, not a claim of complete analyzer parity.

## Compatibility and SemVer policy

This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html) from `1.0.0`.

**Public PHP API.** `AlfredBez\MagoPhpunit\PhpUnitExtension::create()`, `plugin()`, `linterRules()`, and `VERSION` are the supported entry points. Analyzer plugins, providers, hooks, and linter helper classes under `AlfredBez\MagoPhpunit\Analyzer` and `AlfredBez\MagoPhpunit\Linter` are `@internal`. They can change without a major version bump.

**Worker.** `bin/mago-phpunit-worker` (Composer `vendor/bin/mago-phpunit-worker`) is the supported CLI. Stdout carries the Mago extension protocol. Diagnostics and warnings go to stderr.

**Diagnostic and lint codes.** All analyzer codes in the Checks table are enabled by default. The three default-on lint rules (`strict-assertions`, `use-specific-assertions`, `assertion-style-static`) and five opt-in lint rules follow the table above. Adding a new default-on diagnostic or lint rule is a minor bump. Changing the meaning of an existing code, removing a code, or turning an opt-in lint rule on by default is a major bump.

**Suppression.** Project-wide `[analyzer] ignore = ["alfredbez/phpunit/<code>"]`, per-expression `// @mago-ignore analysis:alfredbez/phpunit/<code>`, per-rule `[linter.rules."alfredbez/phpunit/<code>"] enabled = false`, and `// @mago-ignore lint:alfredbez/phpunit/<code>` remain supported.

**Supported floors.** PHP `~8.1` through `~8.6` with `ext-tokenizer`, Mago `^1.60.2` (verified on `1.60.2` and `1.61.0`), and PHPUnit `^10.5 || ^11.5 || ^12.0 || ^13.0`.

## Known limitations

- Expressions whose plain type comes only from an external return-type provider or unmodeled dynamic flow (for example, an `object`-returning helper narrowed by another plugin) can still produce both native and extension mock-call reports. Mago `1.60.2` and `1.61.0` run `IssueFilterHook` before exposing full inferred expression types, so the filter stays conservative when a plain receiver cannot be proved safely from source and codebase metadata.
- PHPUnit 10.5's native `@psalm-assert empty` annotation makes Mago infer `never` for an empty Countable and discard the valid string `"0"`. The extension deliberately avoids duplicating native assertions. Runtime tests prove both values pass PHPUnit, and analyzer tests preserve parity with `--no-extensions` for this inherited defect.
- When a property is initialized to `[]` and mutated only inside a closure before `assertCount(1, $obj->prop)`, Mago core still types the property as `array{}`. Intersecting `array{}` with `non-empty-array` can produce `no-value` until Mago tracks closure mutations.
- Exact array count return typing applies to normal `count()`. Recursive or dynamic modes, optional shapes, unknown lengths, and Countable objects keep native behavior.
- Property shapes apply only to generic objects, mixed values, and compatible object shapes. They do not replace concrete class types.
- Linting has syntax information rather than analyzer type metadata. Inherited calls and provider casing resolve within the same file; external non-TestCase parent chains remain silent.
- Non-literal keys, type names, provider rows, and mock method names retain uncertainty. Partial-mock APIs conservatively skip protected and missing method checks for the file. Final and private methods remain checked.
- Mago's existing property-initialization and intersection-comparison limits still apply. Docblock `@depends` is not parsed.

## For extension authors

Reuse the analyzer plugin and lint rules in another worker:

```php
use AlfredBez\MagoPhpunit\PhpUnitExtension;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

$extension = new Extension(
    identifier: 'app/tests',
    name: 'Application tests',
    version: '1.0.0',
    analyzerPlugins: [PhpUnitExtension::plugin()],
    linterRules: PhpUnitExtension::linterRules(),
);
(new Worker($extension))->run();
```

`plugin()` returns `Mago\Sdk\Analyzer\Plugin`. `linterRules()` returns a list of `Mago\Sdk\Linter\Rule`. The bundled worker composes both through `PhpUnitExtension::create()`.

## Development

```shell
composer install
php tests/check-spec.php
composer test
php tools/corpus-run.php --check
php bench/run-local.php --runs=5
```

The suite runs real analyzer, lint, autofix, PHPUnit, and Prophecy contracts. The conformance catalog pins runtime sources and positive/negative cases. Corpus baselines must be audited before updating.

## License

MIT. See [LICENSE](LICENSE).
