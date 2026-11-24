# Changelog

All notable changes to `alfredbez/mago-phpunit` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
