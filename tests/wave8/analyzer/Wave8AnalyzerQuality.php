<?php

declare(strict_types=1);

namespace Wave8\Analyzer;

use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\RequiresPhpunit;
use PHPUnit\Framework\TestCase;
use function PHPUnit\Framework\assertIsInt;

class ParentFixtures extends TestCase
{
    protected function setUp(): void {}
    protected function tearDown(): void {}
}

#[RequiresPhp('8.2')]
#[RequiresPhpunit('>= 11.0')]
final class QualityTest extends ParentFixtures
{
    protected function setUp(): void {}
    protected function tearDown(): void { parent::tearDown(); }

    /** @dataProvider */
    public function testMetadata(): void {}

    /** @param int|string|null $unknown */
    public function testQuality(string $known, int|string|null $unknown): void
    {
        self::assertNotNull($known);
        self::assertIsString($known);
        self::assertSame(1, 1);
        self::assertNull(null);
        self::assertTrue(true);
        self::assertFalse(false);
        self::assertSame(1, count([$known]));
        self::assertSame(0, (new \ArrayObject())->count());
        assertIsInt(4);
        self::assertNotNull($unknown);
        self::assertIsString($unknown);
        self::assertSame(1, strlen($known));
        self::assertInstanceOf(\stdClass::class, new \stdClass());
        self::assertIsArray([]);
        self::assertIsList([1, 2]);
        self::assertIsNotString(42);
        self::assertIsNotInt('text');
        self::assertIsNotArray('text');
        self::assertIsNotBool('text');
        self::assertIsNotFloat('text');
        self::assertIsNotIterable(42);
        self::assertIsNotNumeric('text');
        self::assertIsNotObject('text');
        self::assertIsNotScalar([]);
        self::assertIsNotCallable(42);
        self::assertIsNotResource('text');
        self::assertIsNotClosedResource('text');
        self::assertIsBool(true);
        self::assertIsFloat(1.5);
        self::assertIsIterable([1]);
        self::assertIsNumeric(42);
        self::assertIsObject(new \stdClass());
        self::assertIsScalar('text');
        self::assertIsCallable(static fn(): int => 1);
    }

    /** @covers\NoWhitespace */
    public function testMalformedCoverage(): void {}

    public function testResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        if ($resource !== false) {
            self::assertIsResource($resource);
            fclose($resource);
        }
    }

    #[RequiresPhp('>= 8.2.0')]
    public function testCompleteRequirement(): void
    {
    }
}

final class MissingTeardownTest extends ParentFixtures
{
    protected function setUp(): void { parent::setUp(); }
    protected function tearDown(): void {}
}

final class NestedParentCallTest extends ParentFixtures
{
    protected function setUp(): void { $unused = function (): void { parent::setUp(); }; }
}

final class DirectFixtureTest extends TestCase
{
    protected function setUp(): void {}
    protected function tearDown(): void {}
}
