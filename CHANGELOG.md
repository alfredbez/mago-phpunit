# Changelog

All notable changes to `alfredbez/mago-phpunit` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

`0.1.0` release candidate targeting Mago `~1.60.2` and PHPUnit `^10.5 || ^11.5 || ^12.0 || ^13.0`.

### Added

- Production worker entrypoint `bin/mago-phpunit-worker` registered under Composer `bin`, with automatic `vendor/autoload.php` discovery for both installed-dependency and local-checkout execution.
- Dist archive hygiene via `.gitattributes` (`export-ignore`) and Packagist-ready `composer.json` metadata.
- Assertion type narrowing for `assertArrayHasKey()`, `assertArrayNotHasKey()`, `assertCount()`, `assertNotEmpty()`, `assertStringContainsString()`, `assertStringContainsStringIgnoringCase()`, `assertStringStartsWith()`, `assertStringEndsWith()`, `assertJson()`, `assertGreaterThan()`, `assertGreaterThanOrEqual()`, `assertLessThan()`, `assertLessThanOrEqual()`, `assertContainsOnly()`, and PHPUnit 10.5 `assertContainsOnlyInstancesOf()` (method and function forms).
- Intersection return-type narrowing for `TestCase::createMockForIntersectionOfInterfaces()` and `createStubForIntersectionOfInterfaces()`.
- `TestCase` property initialization recognition for `setUp()` and `#[Before]` methods across parent classes, traits, and helper calls.
- Data provider, `#[TestWith]`, `#[TestWithJson]`, `#[DataProviderClosure]`, and `#[Depends]` / `#[DependsExternal]` validation (`alfredbez/phpunit/phpunit-data-provider`), including cross-file external providers, PHPUnit 10.5 multi-key AST ordering, dependency return-type matching, and provider declaration checks.
- Coverage target attribute validation (`alfredbez/phpunit/phpunit-invalid-covers-target`) for `#[Covers*]`, `#[Uses*]` class, trait, method, and function attributes.
- Mocked method, `MockBuilder::onlyMethods()` / `addMethods()`, and `willReturn()` / `willReturnOnConsecutiveCalls()` validation (`alfredbez/phpunit/phpunit-unknown-mocked-method`, `alfredbez/phpunit/phpunit-invalid-mock-builder-method`, `alfredbez/phpunit/phpunit-incompatible-mock-return`).
- Pinned conformance catalog (`spec/catalog.json` with 28 cases), PHPUnit 10.5 through 13.0 CI matrix, pinned OSS corpus regression harness, and CodSpeed benchmarks.

### Changed

- Aligned `composer test` to run both the analyzer fixture suite (`@test:analyzer`) and the PHPUnit runtime contract suite (`@test:runtime`).
