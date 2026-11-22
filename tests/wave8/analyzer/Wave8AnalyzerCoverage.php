<?php

declare(strict_types=1);

namespace Wave8\Analyzer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

interface CoveredInterface { public function run(): void; }
trait CoveredTrait { public function traitMethod(): void {} }
class CoveredParent { public function inherited(): void {} }
class CoveredClass extends CoveredParent { use CoveredTrait; public function run(): void {} }

#[CoversClass(CoveredClass::class)]
#[CoversMethod(CoveredClass::class, 'run')]
#[CoversMethod(CoveredClass::class, 'traitMethod')]
final class ValidCoverageTest extends TestCase { public function testValid(): void {} }

#[CoversClass(\stdClass::class)]
#[CoversClass(CoveredInterface::class)]
#[CoversClass(CoveredTrait::class)]
#[CoversClass('\\Wave8\\Analyzer\\CoveredClass')]
#[CoversMethod(CoveredClass::class, 'inherited')]
#[CoversMethod(CoveredInterface::class, 'run')]
#[CoversFunction('strlen')]
final class InvalidCoverageTest extends TestCase { public function testInvalid(): void {} }

/**
 * @coversDefaultClass \Wave8\Analyzer\CoveredClass
 * @covers \Wave8\Analyzer\CoveredClass
 */
final class LegacyCoverageTest extends TestCase
{
    /** @covers \Wave8\Analyzer\CoveredClass */
    public function testRedundant(): void {}

    /** @covers ::run */
    public function testDefault(): void {}

    /** @covers \Wave8\Analyzer\CoveredInterface */
    public function testInterface(): void {}
}

/** @covers ::strlen */
final class MissingDefaultCoverageTest extends TestCase { public function testInvalid(): void {} }

/**
 * @coversDefaultClass \Wave8\Analyzer\CoveredClass
 * @coversDefaultClass \Wave8\Analyzer\CoveredParent
 */
final class DuplicateDefaultCoverageTest extends TestCase { public function testInvalid(): void {} }

/** @coversDefaultClass \Wave8\Analyzer\MissingDefaultClass */
final class InterfaceDefaultCoverageTest extends TestCase { public function testInvalid(): void {} }

/**
 * @covers \Wave8\Analyzer\CoveredClass
 * @covers \Wave8\Analyzer\CoveredClass
 */
final class DuplicateLegacyCoverageTest extends TestCase { public function testInvalid(): void {} }

/** @covers 123 */
final class NonClassLegacyCoverageTest extends TestCase { public function testInvalid(): void {} }

/**
 * @covers \Wave8\Analyzer\CoveredClass::<public>
 * @covers \Wave8\Analyzer\CoveredClass::<protected>
 * @covers \Wave8\Analyzer\CoveredClass::<private>
 * @covers \Wave8\Analyzer\CoveredClass::*
 */
final class UnsupportedLegacySelectorsTest extends TestCase { public function testInvalid(): void {} }

function coveredFunction(): int { return 42; }

/** @covers \Wave8\Analyzer\coveredFunction */
final class LegacyFunctionCoverageTest extends TestCase { public function testValid(): void {} }

/** @covers ::Wave8\Analyzer\coveredFunction */
final class LegacyFunctionWithoutDefaultTest extends TestCase { public function testValid(): void {} }

/** @covers \Wave8\Analyzer\CoveredTrait */
final class LegacyTraitCoverageTest extends TestCase { public function testValid(): void {} }

/** @covers \Wave8\Analyzer\CoveredInterface::run */
final class LegacyInterfaceMethodCoverageTest extends TestCase { public function testValid(): void {} }

/** @covers \Wave8\Analyzer\CoveredTrait::traitMethod */
final class LegacyTraitMethodCoverageTest extends TestCase { public function testValid(): void {} }

/** @covers ::wave8MissingFunction */
final class LegacyMissingFunctionCoverageTest extends TestCase { public function testInvalid(): void {} }

/** @covers \Wave8\Analyzer\CoveredClass:: */
final class LegacyMissingMethodCoverageTest extends TestCase { public function testInvalid(): void {} }

/** @coversDefaultClass \Wave8\Analyzer\CoveredInterface */
final class LegacyInterfaceDefaultTest extends TestCase
{
    /** @covers ::run */
    public function testValid(): void {}
}

/** @coversDefaultClass \Wave8\Analyzer\CoveredTrait */
final class LegacyTraitDefaultTest extends TestCase
{
    /** @covers ::traitMethod */
    public function testValid(): void {}
}
