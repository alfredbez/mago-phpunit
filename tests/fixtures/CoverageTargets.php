<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesFunction;
use PHPUnit\Framework\Attributes\UsesMethod;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

trait CoverageHelperTrait
{
    public function fromTrait(): int
    {
        return 2;
    }
}

abstract class CoverageBaseSubject
{
    public function inheritedMethod(): int
    {
        return 1;
    }
}

final class CoverageSubject extends CoverageBaseSubject
{
    use CoverageHelperTrait;

    public function run(): int
    {
        return 3;
    }

    protected function hiddenMethod(): int
    {
        return 4;
    }
}

function coverageHelperFunction(): int
{
    return 1;
}

final class CoverageTargetConstants
{
    public const TRAIT_NAME = CoverageHelperTrait::class;
    public const FUNC_PREFIX = 'AlfredBez\\MagoPhpunit\\Fixtures\\';
}

#[CoversClass(CoverageSubject::class)]
#[CoversTrait(CoverageHelperTrait::class)]
#[CoversTrait(CoverageTargetConstants::TRAIT_NAME)]
#[CoversMethod(CoverageSubject::class, 'run')]
#[CoversMethod(CoverageSubject::class, 'inheritedMethod')]
#[CoversMethod(CoverageSubject::class, 'fromTrait')]
#[CoversMethod(CoverageSubject::class, 'hiddenMethod')]
#[CoversMethod(CoverageSubject::class, 'RUN')]
#[CoversFunction('AlfredBez\\MagoPhpunit\\Fixtures\\coverageHelperFunction')]
#[CoversFunction(CoverageTargetConstants::FUNC_PREFIX . 'coverageHelperFunction')]
#[UsesClass(CoverageBaseSubject::class)]
#[UsesTrait(CoverageHelperTrait::class)]
#[UsesMethod(CoverageSubject::class, 'run')]
#[UsesFunction('\\AlfredBez\\MagoPhpunit\\Fixtures\\coverageHelperFunction')]
final class ValidCoverageTargetsTest extends TestCase
{
    public function testValidTargets(): void
    {
        self::assertSame(1, coverageHelperFunction());
    }
}

#[CoversClass('AlfredBez\\MagoPhpunit\\Fixtures\\NonExistentCoveredClass')]
#[UsesClass('AlfredBez\\MagoPhpunit\\Fixtures\\NonExistentUsedClass')]
#[CoversTrait('AlfredBez\\MagoPhpunit\\Fixtures\\NonExistentCoveredTrait')]
#[UsesTrait('AlfredBez\\MagoPhpunit\\Fixtures\\NonExistentUsedTrait')]
#[CoversMethod(CoverageSubject::class, 'nonExistentCoveredMethod')]
#[UsesMethod(CoverageSubject::class, 'nonExistentUsedMethod')]
#[CoversFunction('AlfredBez\\MagoPhpunit\\Fixtures\\nonExistentCoveredFunction')]
#[UsesFunction('\\AlfredBez\\MagoPhpunit\\Fixtures\\nonExistentUsedFunction')]
#[CoversFunction('coverageHelperFunction')]
final class InvalidCoverageTargetsTest extends TestCase
{
    public function testInvalidTargets(): void
    {
        self::assertTrue(true);
    }
}
