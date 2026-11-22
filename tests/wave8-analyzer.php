<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/wave8/contracts.php';
chdir(dirname(__DIR__));

$version = PHPUnit\Runner\Version::id();
$major = (int) explode('.', $version)[0];
$modern = version_compare($version, '12.5', '>=');
$legacy = $major <= 11;
$suite = new Wave8Contracts('tests/wave8/analyzer');
$quality = 'Wave8AnalyzerQuality.php';
$suite->expect($quality, "#[RequiresPhp('8.2')]", 'requires-without-operator', 'Use an explicit comparison operator in the required version.');
$suite->expect($quality, "#[RequiresPhpunit('>= 11.0')]", 'incomplete-requires-version', 'Write a complete major.minor.patch version in the requirement.', $modern ? 1 : 0);
$suite->expect($quality, '    protected function setUp(): void {}' . "\n    protected function tearDown(): void { parent", 'missing-parent-call', 'setUp() overrides a parent fixture without calling parent::setUp().');
$suite->expect($quality, '    protected function tearDown(): void {}' . "\n}" . "\n\nfinal class Nested", 'missing-parent-call', 'tearDown() overrides a parent fixture without calling parent::tearDown().');
$suite->expect($quality, '    protected function setUp(): void { $unused', 'missing-parent-call', 'setUp() overrides a parent fixture without calling parent::setUp().');
$suite->expect($quality, '    public function testMetadata()', 'annotation-without-value', 'Annotation @dataProvider requires whitespace followed by a value.', $legacy ? 1 : 0);
$suite->expect($quality, '    public function testMalformedCoverage()', 'annotation-without-value', 'Annotation @covers requires whitespace followed by a value.', $legacy ? 1 : 0);
foreach ([
    'self::assertNotNull($known)', 'self::assertIsString($known)', 'self::assertSame(1, 1)',
    'self::assertNull(null)', 'self::assertTrue(true)', 'self::assertFalse(false)', 'assertIsInt(4)',
    'self::assertSame(1, count(',
    'self::assertInstanceOf(\stdClass::class', 'self::assertIsArray([])', 'self::assertIsBool(true)',
    'self::assertIsFloat(1.5)', 'self::assertIsIterable([1])', 'self::assertIsNumeric(42)',
    'self::assertIsObject(new', "self::assertIsScalar('text')", 'self::assertIsCallable(static', 'self::assertIsResource($resource)',
    'self::assertIsList([1, 2])', 'self::assertIsNotString(42)', "self::assertIsNotInt('text')",
    "self::assertIsNotArray('text')", "self::assertIsNotBool('text')", "self::assertIsNotFloat('text')",
    'self::assertIsNotIterable(42)', "self::assertIsNotNumeric('text')", "self::assertIsNotObject('text')",
    'self::assertIsNotScalar([])', 'self::assertIsNotCallable(42)', "self::assertIsNotResource('text')", "self::assertIsNotClosedResource('text')",
] as $target) {
    preg_match('/(assert\w+)\(/', $target, $match);
    $suite->expect($quality, $target, 'redundant-assertion', 'Assertion ' . strtolower($match[1]) . '() always passes for the static argument type.');
}
foreach (['self::assertSame(1, count(', 'self::assertSame(0, (new'] as $target) {
    $suite->expect($quality, $target, 'assert-same-count', 'Use assertCount() to compare a collection count.');
}
$providers = 'Wave8AnalyzerProviders.php';
foreach (['emptyGenerator' => 'testEmptyGenerator', 'emptyRows' => 'testEmpty'] as $method => $test) {
    $suite->expect($providers, "#[DataProvider('{$method}')]\n    public function {$test}(", 'phpunit-data-provider', "All local data providers for {$test}() are empty.");
}
$suite->expect($providers, "#[DataProvider('emptyRows', skipWhenEmpty: true)]", 'phpunit-data-provider', 'All local data providers for testSkipEmpty() are empty.', $major >= 13 ? 0 : 1);
$suite->expect($providers, '    public static function secondRows()', 'phpunit-data-provider', 'Duplicate dataset key "same" across providers for testDuplicate().');
$suite->expect($providers, '    public static function testProvider()', 'data-provider-is-test', 'Data provider testProvider() is also a test method.');
$suite->expect($providers, '#[Test]' . "\n    public static function rowsAsTest", 'data-provider-is-test', 'Data provider rowsAsTest() is also a test method.');
$suite->expect($providers, "#[TestWith([2], 'same')]", 'phpunit-data-provider', 'Duplicate TestWith dataset key "same" for testDuplicateWith().', $major >= 11 ? 1 : 0);
$suite->expect($providers, "#[DataProvider('firstRows')]\n    #[TestWith", 'mixed-data-provider', 'Test testMixed() combines TestWith and DataProvider metadata.', $modern ? 1 : 0);
$suite->expect($providers, '    public static function emptyKey()', 'phpunit-data-provider', 'Dataset keys must not be empty for testEmptyKey().', $major >= 13 ? 1 : 0);
$suite->expect($providers, '    public function testDocInvalid(', 'phpunit-data-provider', 'Annotation @testWith row #0 provides 1 argument for testDocInvalid(), which requires 2.', $legacy ? 1 : 0);
$suite->expect($providers, '    public function testDocInvalid(', 'phpunit-data-provider', "Annotation @testWith row #0 provides 'wrong' for \$id of testDocInvalid(), which expects int.", $legacy ? 1 : 0);
$suite->expect($providers, '    public function testDocJson(', 'phpunit-data-provider', 'Annotation @testWith row #0 for testDocJson() contains invalid JSON.', $legacy ? 1 : 0);
$suite->expect($providers, '    public function testDocMultilineInvalid(', 'phpunit-data-provider', "Annotation @testWith row #1 provides 'wrong' for \$id of testDocMultilineInvalid(), which expects int.", $legacy ? 1 : 0);
$coverage = 'Wave8AnalyzerCoverage.php';
foreach ([
    ['#[CoversClass(\stdClass::class)]', '#[CoversClass] target `stdClass` is not a coverable class.', 1],
    ['#[CoversClass(CoveredInterface::class)]', '#[CoversClass] target `Wave8\Analyzer\CoveredInterface` is not a coverable class.', $major >= 11 ? 1 : 0],
    ['#[CoversClass(CoveredTrait::class)]', '#[CoversClass] target `Wave8\Analyzer\CoveredTrait` is not a coverable class.', $major >= 12 ? 1 : 0],
    ["#[CoversClass('\\\\Wave8", '#[CoversClass] target `Wave8\Analyzer\CoveredClass` is not a coverable class.', $major >= 12 ? 1 : 0],
    ["#[CoversMethod(CoveredClass::class, 'inherited')]", '#[CoversMethod] target `Wave8\Analyzer\CoveredClass::inherited()` is not declared by a coverable target.', $major >= 12 ? 1 : 0],
    ["#[CoversMethod(CoveredInterface::class, 'run')]", '#[CoversMethod] target `Wave8\Analyzer\CoveredInterface::run()` is not declared by a coverable target.', $major >= 12 ? 1 : 0],
    ["#[CoversFunction('strlen')]", '#[CoversFunction] target function `strlen` does not exist.', 1],
] as [$target, $message, $count]) {
    $suite->expect($coverage, $target, 'phpunit-invalid-covers-target', $message, $count);
}
$suite->expect($coverage, '    public function testRedundant()', 'redundant-covers', '@covers target `\Wave8\Analyzer\CoveredClass` is already declared on the test class.', $legacy ? 1 : 0);
$suite->expect($coverage, '    public function testInterface()', 'phpunit-invalid-covers-target', '@covers target `\Wave8\Analyzer\CoveredInterface` is not a coverable class, trait, or function.', $legacy ? 1 : 0);
foreach ([
    'MissingDefaultCoverageTest' => '@covers target `::strlen` is not a coverable class method.',
    'DuplicateDefaultCoverageTest' => 'Duplicate @coversDefaultClass annotations are not allowed.',
    'InterfaceDefaultCoverageTest' => '@coversDefaultClass target `Wave8\Analyzer\MissingDefaultClass` is not a coverable class.',
    'DuplicateLegacyCoverageTest' => 'Duplicate @covers target `\Wave8\Analyzer\CoveredClass`.',
    'NonClassLegacyCoverageTest' => '@covers target `123` is not a coverable class, trait, or function.',
    'LegacyMissingFunctionCoverageTest' => '@covers target `::wave8MissingFunction` is not a coverable class method.',
    'LegacyMissingMethodCoverageTest' => '@covers target `\Wave8\Analyzer\CoveredClass::` is not a coverable class method.',
] as $target => $message) {
    $suite->expect($coverage, 'final class ' . $target, 'phpunit-invalid-covers-target', $message, $legacy ? 1 : 0);
}
foreach (['<public>', '<protected>', '<private>', '*'] as $selector) {
    $suite->expect($coverage, 'final class UnsupportedLegacySelectorsTest', 'phpunit-invalid-covers-target', "@covers target `\\Wave8\\Analyzer\\CoveredClass::{$selector}` is not a coverable class method.", $legacy ? 1 : 0);
}
$suite->verify();
