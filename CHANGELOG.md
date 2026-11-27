# Changelog

All notable changes to `alfredbez/mago-phpunit` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-11-27

### Added

- `MockMethodCallHook` validates `expects()->method()` targets across `InvocationStubber::method`, `Builder\InvocationStubber::method`, and `Builder\InvocationMocker::method` (covering PHPUnit 10 through 13) with fluent `expects()`, `id()`, and `after()` receiver recovery, while preserving partial-mock suppression and conservative fallback on unresolved receivers.
- `MockBuilderAndExpectationHook::resolveMockMethodChain()` validates `willReturn()` and `willReturnOnConsecutiveCalls()` chains rooted at method-call mock receivers (such as `$this->createMock(Foo::class)->method(...)->willReturn(...)` and helper factories).
- `DataProviderArguments` indexes `NodeKind::Trait` alongside classes without descending into method bodies and resolves trait-defined, `insteadof`-disambiguated, cross-file trait, and cross-file inherited `#[DataProvider]` and `#[DataProviderExternal]` methods deterministically across single-worker and multi-worker runs.

### Fixed

- `PhpUnitMethodAssertions::contains()` and `isList()` collect sealed `ListType::$knownElements` and skip `never` fallback element types so `assertContains($value, [1, 2])` no longer narrows `$value` to `never`.
- `PhpUnitMethodAssertions` and `AssertArrayHasKeyAssertion` guard against immutable constant expressions (including reserved-keyword class constants) so assertions on class constants do not corrupt constant types to `mixed`, and exclude `iterable` and named-object (`ArrayAccess`) arguments while retaining array-key narrowing on plain arrays, templated arrays, `mixed`, and nullable or object-union arrays.
- `PhpUnitRule::assertionStyle()` returns early for `assertion-style-this` inside static methods, static closures, and static arrow functions where `$this` is unavailable.
- `PhpUnitRule::testAttribute()` skips `abstract` methods and same-file overridden parent or child `test*()` methods so `prefer-test-attribute` autofixes preserve valid PHP inheritance and PHPUnit test discovery.

## [1.0.1] - 2026-11-26

### Fixed

- `DataProviderArguments::methodsInFile()` walks from the AST root without descending into class, interface, trait, enum, function, expression, or attribute subtrees, and reads class identifiers and methods in a single child pass instead of scanning every node in the file via `SourceFile::getNodes(NodeKind::Class_)`.

## [1.0.0] - 2026-11-26

First stable release targeting PHP `~8.1` through `~8.6`, Mago `^1.60.2` (verified on `1.60.2` and `1.61.0`), and PHPUnit `^10.5 || ^11.5 || ^12.0 || ^13.0`.

### Added

- PHPUnit lifecycle entry points for `#[Test]`, `#[Before]`, `#[After]`, `#[BeforeClass]`, `#[AfterClass]`, `#[PreCondition]`, `#[PostCondition]`, `setUp()`, `tearDown()`, `setUpBeforeClass()`, `tearDownAfterClass()`, `assertPreConditions()`, and `assertPostConditions()` so `find-unused-definitions = true` does not report unused test hooks.
- `BeforeAnalysisHook` cache reset across repeated analysis runs in long-lived workers.
- Compatibility and SemVer policy, `@internal` annotations on non-public classes, worker memory guidance (`-d memory_limit=512M` for large suites), floor-compatible PHP `8.1` and `8.2` CI lanes, explicit Mago `1.60.2` and `1.61.0` CI pins, and bounded Mago self-analysis in CI.

### Fixed

- `LegacyMockSourceIndex` finality check now uses `MetadataFlags::FINAL` so `static::` factory calls inside `final` test classes resolve cleanly.
- `ProphecySupport` removes a non-existent `CallArgument::$placeholder` read and stops reporting `prophecy-union` on nullable `?ObjectProphecy<T>` parameters.
- `PHPUnit\Framework\assertContainsOnlyInstancesOf()` (function form) is now registered alongside the static method form for PHPUnit 10.5 narrowing.
- `mock-call-on-plain-type` now requires PHPUnit-compatible single arguments for `expects()` (`InvocationRule`, `InvocationOrder`, `object`, or `mixed`) and `method()` (`string`, `Constraint`, or `mixed`), preventing false positives on non-PHPUnit doubles such as `$double->expects('store')`.
- Cross-file `#[DataProviderExternal]` validation now queries codebase metadata deterministically in `afterAnalysis()`, producing identical diagnostics across single-worker and multi-worker pools.
- Per-file AST and token caches are now bounded to the active file and node hooks defer full AST fetches until a call matches, cutting peak worker memory so large test suites fit within 128M while documenting `-d memory_limit=512M` for headroom.
- Production corpus checks now run `bin/mago-phpunit-worker` without overlapping `paths` and `includes`.

## [0.1.3] - 2026-11-25

### Fixed

- `willReturnOnConsecutiveCalls()` resolves unambiguous same-scope local `Stub` variables and alias chains (`ReturnStub`, `ReturnCallback`, `ReturnSelf`, `ReturnArgument`, `ReturnValueMap`, `ConsecutiveCalls`, and `Stub\Exception`), while staying conservative on reassigned, conditional, loop-mutated, by-reference, dynamic-scope, or helper-returned locals.
- `willReturnOnConsecutiveCalls()` validates `ReturnSelf` against the mocked method's declared return type (including `self`, `static`, `parent`, interfaces, unions, and intersections), validates `ReturnArgument` against declared parameter types and bounds (catching partial-union, negative, and out-of-range mismatches), validates `ReturnValueMap` row return payloads, recursively checks nested `ConsecutiveCalls`, and preserves an explicit conservative boundary for mutable `ReturnReference` bindings.

## [0.1.2] - 2026-11-25

### Fixed

- `willReturnOnConsecutiveCalls()` validates wrapped `ReturnStub` values and resolvable `ReturnCallback` return types against the mocked method's declared return type while preserving `Stub\Exception` exemptions, `never` callbacks on `never` methods, and conservative handling for opaque stubs and mixed stub unions.
- `#[TestWith]` resolves existing class, interface, trait, and enum constants (including whole-row array constants and recursive constant aliases with cycle protection) through Mago codebase metadata while preserving enum-case and backed-enum `->value` handling and leaving missing constants to Mago core.

## [0.1.1] - 2026-11-24

### Fixed

- `willReturnOnConsecutiveCalls()` accepts PHPUnit `Stub` objects such as `$this->throwException(...)` on typed methods and accepts `Stub\Exception` on `never` methods, while `willReturn()` continues rejecting direct `Stub` instances.
- `#[TestWith]` argument validation resolves unit enum cases, backed enum cases, and backed enum `->value` literals from attribute metadata instead of rejecting valid enum rows.

## [0.1.0] - 2026-11-22

Version `0.1.0` targets Mago `^1.60.2` (verified on `1.60.2` and `1.61.0`) and PHPUnit `^10.5 || ^11.5 || ^12.0 || ^13.0`.

### Added

- Assertion narrowing for object properties, exact array counts, excluded singleton values, contains/not-contains, empty values, and PHPUnit 10.5 lists, with method and function forms.
- Trait-double return types on PHPUnit 10/11, legacy mock union compatibility, one plain-type mock API diagnostic, stricter configured method checks, and framework exception filtering scoped to test classes.
- Redundant assertions, count comparisons, missing parent fixtures, provider completeness, legacy TestWith JSON, coverage completeness, requirement versions, and malformed annotation checks.
- Optional Prophecy typing, mutation assertions, tokens, setup checks, and return/argument/yield promise validation. Prophecy is a development dependency only.
- Eight PHPUnit lint rules with documented defaults and safe or potentially unsafe fixes, plus public `PhpUnitExtension::plugin()` and `linterRules()` composition helpers.
- Asymmetric public CLI, runtime, and autofix contracts. PHPUnit 10's inherited native empty-annotation limitation is explicitly preserved and documented.
- Production worker entrypoint `bin/mago-phpunit-worker` registered under Composer `bin`, with automatic `vendor/autoload.php` discovery for both installed-dependency and local-checkout execution.
- Dist archive hygiene via `.gitattributes` (`export-ignore`) and Packagist-ready `composer.json` metadata.
- Assertion type narrowing for `assertArrayHasKey()`, `assertArrayNotHasKey()`, `assertCount()`, `assertNotEmpty()`, `assertStringContainsString()`, `assertStringContainsStringIgnoringCase()`, `assertStringStartsWith()`, `assertStringEndsWith()`, `assertJson()`, `assertGreaterThan()`, `assertGreaterThanOrEqual()`, `assertLessThan()`, `assertLessThanOrEqual()`, `assertContainsOnly()`, and PHPUnit 10.5 `assertContainsOnlyInstancesOf()` (method and function forms).
- Intersection return-type narrowing for `TestCase::createMockForIntersectionOfInterfaces()` and `createStubForIntersectionOfInterfaces()`.
- `TestCase` property initialization recognition for `setUp()` and `#[Before]` methods across parent classes, traits, and helper calls.
- Data provider, `#[TestWith]`, `#[TestWithJson]`, `#[DataProviderClosure]`, and `#[Depends]` / `#[DependsExternal]` validation (`alfredbez/phpunit/phpunit-data-provider`), including cross-file external providers, PHPUnit 10.5 multi-key AST ordering, dependency return-type matching, and provider declaration checks.
- Coverage target attribute validation (`alfredbez/phpunit/phpunit-invalid-covers-target`) for `#[Covers*]`, `#[Uses*]` class, trait, method, and function attributes.
- Mocked method, `MockBuilder::onlyMethods()` / `addMethods()`, and `willReturn()` / `willReturnOnConsecutiveCalls()` validation (`alfredbez/phpunit/phpunit-unknown-mocked-method`, `alfredbez/phpunit/phpunit-invalid-mock-builder-method`, `alfredbez/phpunit/phpunit-incompatible-mock-return`).
- Pinned conformance catalog (`spec/catalog.json`), PHPUnit 10.5 through 13.0 CI matrix, pinned OSS corpus regression harness, and CodSpeed benchmarks.

### Changed

- Rewrote the guide around installation, caught bugs, assertion facts, diagnostic tables, and lint configuration.
- Benchmark instrumentation now uses production plugin registration, and corpus JSON retains all added diagnostics for review.
- Aligned `composer test` to run both the analyzer fixture suite (`@test:analyzer`) and the PHPUnit runtime contract suite (`@test:runtime`).
