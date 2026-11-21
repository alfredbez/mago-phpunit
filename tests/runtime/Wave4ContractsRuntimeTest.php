<?php

declare(strict_types=1);

use AlfredBez\MagoPhpunit\Fixtures\CoverageHelperTrait;
use AlfredBez\MagoPhpunit\Fixtures\CoverageSubject;
use AlfredBez\MagoPhpunit\Fixtures\IntersectionClock;
use AlfredBez\MagoPhpunit\Fixtures\IntersectionLogger;
use AlfredBez\MagoPhpunit\Fixtures\MockedLetters;
use AlfredBez\MagoPhpunit\Fixtures\MockedMailer;
use AlfredBez\MagoPhpunit\Fixtures\MockedNumbers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\MockObject\MethodCannotBeConfiguredException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/fixtures/IntersectionMocks.php';
require_once dirname(__DIR__) . '/fixtures/CoverageTargets.php';
require_once dirname(__DIR__) . '/fixtures/MockMethodCalls.php';

#[CoversClass(CoverageSubject::class)]
#[CoversMethod(CoverageSubject::class, 'run')]
#[CoversFunction('AlfredBez\\MagoPhpunit\\Fixtures\\coverageHelperFunction')]
final class Wave4ContractsRuntimeTest extends TestCase
{
    public function testCreateMockAndStubForIntersectionOfInterfacesAtRuntime(): void
    {
        $mock = $this->createMockForIntersectionOfInterfaces([
            IntersectionClock::class,
            IntersectionLogger::class,
        ]);
        self::assertInstanceOf(IntersectionClock::class, $mock);
        self::assertInstanceOf(IntersectionLogger::class, $mock);
        $mock->method('now')->willReturn(42);
        $mock->expects($this->once())->method('log')->with('ready');
        $mock->log('ready');
        self::assertSame(42, $mock->now());

        $stub = self::createStubForIntersectionOfInterfaces([
            IntersectionClock::class,
            IntersectionLogger::class,
        ]);
        self::assertInstanceOf(IntersectionClock::class, $stub);
        self::assertInstanceOf(IntersectionLogger::class, $stub);
        $stub->method('now')->willReturn(99);
        self::assertSame(99, $stub->now());
    }

    public function testMockMethodValidationMatchesPhpUnitRuntimeBehavior(): void
    {
        $numbers = self::createStub(MockedNumbers::class);
        $numbers->method('current')->willReturn(7);
        self::assertSame(7, $numbers->current());

        $mailer = self::createStub(MockedMailer::class);
        $this->expectException(MethodCannotBeConfiguredException::class);
        $mailer->method('sendInvoce');
    }

    public function testIteratorAggregateDoubleRejectsCurrentMethodAtRuntime(): void
    {
        $letters = self::createStub(MockedLetters::class);
        $this->expectException(MethodCannotBeConfiguredException::class);
        $letters->method('current');
    }

    public function testCoverageTargetsExistAtRuntime(): void
    {
        $subject = new CoverageSubject();
        self::assertSame(3, $subject->run());
        self::assertSame(1, $subject->inheritedMethod());
        self::assertSame(2, $subject->fromTrait());
        self::assertTrue(trait_exists(CoverageHelperTrait::class));
        self::assertTrue(function_exists('AlfredBez\\MagoPhpunit\\Fixtures\\coverageHelperFunction'));
    }
}
