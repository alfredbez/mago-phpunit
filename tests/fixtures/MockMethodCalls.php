<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use ArrayIterator;
use IteratorAggregate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Traversable;

interface MockedMailer
{
    public function sendInvoice(): bool;
}

interface MockedNotifier
{
    public function notifyUser(): bool;
}

/**
 * @extends Traversable<int, int>
 */
interface MockedNumbers extends Traversable {}

/**
 * @extends IteratorAggregate<int, string>
 */
interface MockedLetters extends IteratorAggregate {}

/**
 * @method int magicCall()
 */
class MockedWithPseudoMethod
{
    public function realCall(): int
    {
        return 1;
    }
}

class MockedWithMagicCall
{
    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}

final class MockMethodValidationTest extends TestCase
{
    private const TYPO_METHOD = 'sendInvoce';

    private MockObject&MockedMailer $typedMockProperty;
    private Stub&MockedMailer $typedStubProperty;

    protected function setUp(): void
    {
        $this->typedMockProperty = $this->createMock(MockedMailer::class);
        $this->typedStubProperty = $this->createStub(MockedMailer::class);
    }

    public function testValidMockMethodCalls(string $dynamicName): void
    {
        $mock = $this->createMock(MockedMailer::class);
        $mock->method('sendInvoice')->willReturn(true);
        $mock->method('SENDINVOICE')->willReturn(true);
        $mock->method($dynamicName)->willReturn(true);
        $mock->method('$hookedProperty::get')->willReturn(true);

        $stub = $this->createStub(MockedMailer::class);
        $stub->method('sendInvoice')->willReturn(false);

        $pseudo = $this->createStub(MockedWithPseudoMethod::class);
        $pseudo->method('realCall')->willReturn(1);
        $pseudo->method('magicCall')->willReturn(2);

        $magic = $this->createStub(MockedWithMagicCall::class);
        $magic->method('anythingAtAll')->willReturn(123);

        $numbers = $this->createStub(MockedNumbers::class);
        $numbers->method('current')->willReturn(1);
        $numbers->method('rewind');

        $letters = $this->createStub(MockedLetters::class);
        $letters->method('getIterator')->willReturn(new ArrayIterator([]));
    }

    public function testInvalidMockMethodCalls(bool $flag): void
    {
        $mock = $this->createMock(MockedMailer::class);
        $mock->method('sendInvoce')->willReturn(true);

        $stub = $this->createStub(MockedMailer::class);
        $stub->method('sendInvoce')->willReturn(true);

        $this->typedMockProperty->method(self::TYPO_METHOD)->willReturn(true);
        $this->typedStubProperty->method('missingOnStubProperty')->willReturn(true);

        $numbers = $this->createStub(MockedNumbers::class);
        $numbers->method('missingOnTraversable')->willReturn(1);

        $letters = $this->createStub(MockedLetters::class);
        $letters->method('current')->willReturn('a');

        $union = $flag ? $this->createMock(MockedMailer::class) : $this->createMock(MockedNotifier::class);
        $union->method('sendInvoice')->willReturn(true);
    }
}
