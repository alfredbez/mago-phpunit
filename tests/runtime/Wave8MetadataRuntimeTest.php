<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\InvalidDataProviderException;
use PHPUnit\Framework\InvalidCoversTargetException;
use PHPUnit\Metadata\Api\CodeCoverage;
use PHPUnit\Metadata\Api\DataProvider as DataProviderApi;
use PHPUnit\Runner\Version;

final class Wave8MetadataRuntimeTest extends TestCase
{
    public function testLegacyCoverageMapsRuntimeValidTargets(): void
    {
        if ((int) Version::id() >= 12) {
            self::markTestSkipped('Docblock coverage metadata was removed in PHPUnit 12.');
        }
        $coverage = new CodeCoverage();
        foreach (['plainFunction', 'globalFunction', 'traitTarget', 'interfaceMethod'] as $method) {
            self::assertNotEmpty($coverage->linesToBeCovered(Wave8LegacyMetadataCases::class, $method), $method);
        }
        self::assertNotEmpty($coverage->linesToBeCovered(Wave8InterfaceDefaultMetadata::class, 'coveredMethod'));
        self::assertNotEmpty($coverage->linesToBeCovered(Wave8TraitDefaultMetadata::class, 'coveredMethod'));
    }

    public function testLegacyCoverageRejectsPlainInterfacesAndBuiltins(): void
    {
        if ((int) Version::id() >= 12) {
            self::markTestSkipped('Docblock coverage metadata was removed in PHPUnit 12.');
        }
        $coverage = new CodeCoverage();
        foreach (['plainInterface', 'builtinFunction', 'missingFunction', 'malformedMethod'] as $method) {
            try {
                $coverage->linesToBeCovered(Wave8LegacyMetadataCases::class, $method);
                self::fail('Expected invalid coverage target for ' . $method);
            } catch (InvalidCoversTargetException|\SebastianBergmann\CodeUnit\ReflectionException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testLegacyTestWithParsesEveryJsonRow(): void
    {
        if ((int) Version::id() >= 12) {
            self::markTestSkipped('Docblock testWith metadata was removed in PHPUnit 12.');
        }
        self::assertSame([[1, 'first'], [2, 'second']], (new DataProviderApi())->providedData(Wave8LegacyMetadataCases::class, 'validJson'));
    }

    public function testLegacyTestWithRejectsMalformedSecondJsonRow(): void
    {
        if ((int) Version::id() >= 12) {
            self::markTestSkipped('Docblock testWith metadata was removed in PHPUnit 12.');
        }
        $this->expectException(InvalidDataProviderException::class);
        (new DataProviderApi())->providedData(Wave8LegacyMetadataCases::class, 'invalidJson');
    }

    public function testLegacyTestWithArgumentsMustMatchParameters(): void
    {
        if ((int) Version::id() >= 12) {
            self::markTestSkipped('Docblock testWith metadata was removed in PHPUnit 12.');
        }
        $rows = (new DataProviderApi())->providedData(Wave8LegacyMetadataCases::class, 'wrongType');
        self::assertSame([['wrong', 'name']], $rows);
        $this->expectException(\TypeError::class);
        (new Wave8LegacyMetadataCases())->wrongType(...$rows[0]);
    }

    public function testLegacyTestWithRequiresAllArguments(): void
    {
        if ((int) Version::id() >= 12) {
            self::markTestSkipped('Docblock testWith metadata was removed in PHPUnit 12.');
        }
        $rows = (new DataProviderApi())->providedData(Wave8LegacyMetadataCases::class, 'missingArgument');
        self::assertSame([[1]], $rows);
        $this->expectException(\ArgumentCountError::class);
        (new Wave8LegacyMetadataCases())->missingArgument(...$rows[0]);
    }

    public static function firstProvider(): array { return ['first' => [1]]; }
    public static function secondProvider(): array { return ['second' => [2]]; }
    public static function emptyProvider(): array { return []; }

    #[DataProvider('firstProvider')]
    #[DataProvider('secondProvider')]
    public function testDisjointDatasetNames(int $number): void
    {
        self::assertGreaterThan(0, $number);
    }

    #[DataProvider('emptyProvider')]
    #[DataProvider('firstProvider')]
    public function testOneEmptyProviderCanBeCombinedWithRows(int $number): void
    {
        self::assertSame(1, $number);
    }

    #[TestWith([1])]
    #[TestWith([2])]
    public function testUnnamedRowsRemainDistinct(int $number): void
    {
        self::assertGreaterThan(0, $number);
    }
}

function wave8CoveredRuntimeFunction(): int { return 42; }
trait Wave8CoveredRuntimeTrait { public function covered(): int { return 42; } }
interface Wave8CoveredRuntimeInterface { public function covered(): int; }

final class Wave8LegacyMetadataCases
{
    /** @covers AlfredBez\MagoPhpunit\Tests\Runtime\wave8CoveredRuntimeFunction */
    public function plainFunction(): void {}
    /** @covers ::AlfredBez\MagoPhpunit\Tests\Runtime\wave8CoveredRuntimeFunction */
    public function globalFunction(): void {}
    /** @covers AlfredBez\MagoPhpunit\Tests\Runtime\Wave8CoveredRuntimeTrait */
    public function traitTarget(): void {}
    /** @covers AlfredBez\MagoPhpunit\Tests\Runtime\Wave8CoveredRuntimeInterface::covered */
    public function interfaceMethod(): void {}
    /** @covers AlfredBez\MagoPhpunit\Tests\Runtime\Wave8CoveredRuntimeInterface */
    public function plainInterface(): void {}
    /** @covers ::strlen */
    public function builtinFunction(): void {}
    /** @covers ::wave8_missing_runtime_function */
    public function missingFunction(): void {}
    /** @covers AlfredBez\MagoPhpunit\Tests\Runtime\Wave8CoveredRuntimeTrait::* */
    public function malformedMethod(): void {}

    /**
     * @testWith [1, "first"]
     *           [2, "second"]
     */
    public function validJson(int $id, string $name): void {}
    /**
     * @testWith [1, "first"]
     *           [invalid]
     */
    public function invalidJson(int $id, string $name): void {}
    /**
     * @testWith ["wrong", "name"]
     */
    public function wrongType(int $id, string $name): void {}
    /**
     * @testWith [1]
     */
    public function missingArgument(int $id, string $name): void {}
}

/** @coversDefaultClass AlfredBez\MagoPhpunit\Tests\Runtime\Wave8CoveredRuntimeInterface */
final class Wave8InterfaceDefaultMetadata
{
    /** @covers ::covered */
    public function coveredMethod(): void {}
}

/** @coversDefaultClass AlfredBez\MagoPhpunit\Tests\Runtime\Wave8CoveredRuntimeTrait */
final class Wave8TraitDefaultMetadata
{
    /** @covers ::covered */
    public function coveredMethod(): void {}
}
