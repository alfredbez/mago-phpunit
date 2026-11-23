<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Exception\Doubler\ClassMirrorException;
use Prophecy\Exception\InvalidArgumentException;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\MethodProphecy;

interface Wave8RuntimeService
{
    public function name(int $id): string;
    public function echoName(string $name): string;
    public function stream(): \Generator;
    public function reset(): void;
    public function neverReturns(): never;
    public function countValue(): int;
    public function boolValue(): bool;
    public function nullable(): ?string;
    public function narrowed(\stdClass|string $value): string;
}
interface Wave8RuntimeMarker {}
class Wave8RuntimeBase { public function id(): int { return 42; } }
final class Wave8RuntimeFinal {}
class Wave8RuntimeReplacement {}
trait Wave8RuntimeTrait {}
enum Wave8RuntimeEnum { case Ready; }
abstract class Wave8RuntimeRestricted
{
    final public function fixed(): string { return 'fixed'; }
    private function privateMethod(): string { return 'private'; }
    protected function protectedMethod(): string { return 'protected'; }
    abstract protected function abstractProtected(): string;
    public static function staticMethod(): string { return 'static'; }
    public function _hidden(): string { return 'hidden'; }
    public function intersection(): Wave8RuntimeService&Wave8RuntimeMarker { throw new \LogicException(); }
    public function __toString(): string { return 'magic'; }
}

final class Wave8ProphecyRuntimeTest extends TestCase
{
    use ProphecyTrait;

    public function testMethodProphecyAndReturnPromises(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $method = $service->name(Argument::type('int'));
        self::assertInstanceOf(MethodProphecy::class, $method);
        $method->willReturn('found');
        $service->echoName(Argument::type('string'))->willReturnArgument(0);
        $service->stream()->willYield(['first', 'second']);
        $double = $service->reveal();
        self::assertInstanceOf(Wave8RuntimeService::class, $double);
        self::assertSame('found', $double->name(7));
        self::assertSame('echo', $double->echoName('echo'));
        self::assertSame(['first', 'second'], iterator_to_array($double->stream()));
    }

    public function testHeldArgumentPromiseRetainsLiteralSetup(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $method = $service->narrowed('valid');
        $method->willReturnArgument(0);
        self::assertSame('valid', $service->reveal()->narrowed('valid'));
    }

    public function testHeldArgumentPromiseRejectsObjectSetupAtInvocation(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $value = new \stdClass();
        $method = $service->narrowed($value);
        $method->willReturnArgument(0);
        $this->expectException(\TypeError::class);
        $service->reveal()->narrowed($value);
    }

    public function testParentReplacementRetainsInterfaces(): void
    {
        $p = $this->prophesize(Wave8RuntimeBase::class);
        $p->willImplement(Wave8RuntimeMarker::class);
        $p->willExtend(Wave8RuntimeReplacement::class);
        $double = $p->reveal();
        self::assertInstanceOf(Wave8RuntimeReplacement::class, $double);
        self::assertNotInstanceOf(Wave8RuntimeBase::class, $double);
        self::assertInstanceOf(Wave8RuntimeMarker::class, $double);
    }

    public function testExtensionAndImplementationMutateOneProphecy(): void
    {
        $prophecy = $this->prophesize();
        $prophecy->willExtend(Wave8RuntimeBase::class);
        $prophecy->willImplement(Wave8RuntimeMarker::class);
        $double = $prophecy->reveal();
        self::assertInstanceOf(Wave8RuntimeBase::class, $double);
        self::assertInstanceOf(Wave8RuntimeMarker::class, $double);
    }

    public function testTraversableDoubleImplementsIterator(): void
    {
        $double = $this->prophesize(\Traversable::class)->reveal();
        self::assertInstanceOf(\Iterator::class, $double);
    }

    public function testUnknownTypeTokenIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Argument::type('wave8_unknown_runtime_type');
    }

    public function testTypeTokenDoesNotMatchAnotherScalarType(): void
    {
        self::assertFalse(Argument::type('string')->scoreArgument(42));
        self::assertSame(5, Argument::type('string')->scoreArgument('42'));
    }

    public function testFinalClassCannotBeDoubled(): void
    {
        $this->expectException(ClassMirrorException::class);
        (new \Prophecy\Prophet())->prophesize(Wave8RuntimeFinal::class)->reveal();
    }

    public function testScalarReturnUsesWeakCoercion(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $service->name(1)->willReturn(123);
        self::assertSame('123', $service->reveal()->name(1));
        $service->name(2)->willReturnArgument(0);
        self::assertSame('2', $service->reveal()->name(2));
    }

    public function testVoidReturnPromiseIsRejected(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $this->expectException(\Prophecy\Exception\Prophecy\MethodProphecyException::class);
        $service->reset()->willReturn(null);
    }

    public function testVoidYieldPromiseIsRejected(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $this->expectException(\Prophecy\Exception\Prophecy\MethodProphecyException::class);
        $service->reset()->willYield([]);
    }

    public function testYieldRequiresGeneratorCompatibleReturn(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $service->name(1)->willYield([]);
        $this->expectException(\TypeError::class);
        $service->reveal()->name(1);
    }

    public function testNeverReturnPromiseFailsAtInvocation(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $service->neverReturns()->willReturn('bad');
        $this->expectException(\TypeError::class);
        $service->reveal()->neverReturns();
    }

    public function testNumericExactTokensUseNumericComparison(): void
    {
        self::assertSame(10, Argument::exact('42')->scoreArgument(42));
        self::assertSame(10, Argument::exact(42.0)->scoreArgument(42));
        self::assertFalse(Argument::exact('bad')->scoreArgument(42));
        self::assertFalse(Argument::is('42')->scoreArgument(42));
    }

    public function testScalarReturnCoercionsMatchPhpRuntime(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $service->countValue()->willReturn('42', true);
        $service->boolValue()->willReturn('nonempty');
        $service->name(1)->willReturn(new class { public function __toString(): string { return 'object'; } });
        $double = $service->reveal();
        self::assertSame(42, $double->countValue());
        self::assertSame(1, $double->countValue());
        self::assertTrue($double->boolValue());
        self::assertSame('object', $double->name(1));
    }

    public function testMissingArgumentPromiseReturnsNullWhenAllowed(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $service->nullable()->willReturnArgument(9);
        self::assertNull($service->reveal()->nullable());
    }

    public function testMissingArgumentPromiseFailsForNonNullableReturn(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $method = $service->echoName('name');
        $method->willReturnArgument(9);
        $this->expectException(\TypeError::class);
        $service->reveal()->echoName('name');
    }

    public function testArgumentPromiseRejectsNonIntegerIndex(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $this->expectException(InvalidArgumentException::class);
        $service->echoName('name')->willReturnArgument('bad');
    }

    public function testYieldPromiseRequiresArrayItems(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $this->expectException(InvalidArgumentException::class);
        $service->stream()->willYield('bad');
    }

    public function testTraitCannotBeDoubled(): void
    {
        $this->expectException(\Prophecy\Exception\Doubler\ClassNotFoundException::class);
        (new \Prophecy\Prophet())->prophesize(Wave8RuntimeTrait::class);
    }

    public function testEnumCannotBeDoubled(): void
    {
        $this->expectException(ClassMirrorException::class);
        (new \Prophecy\Prophet())->prophesize(Wave8RuntimeEnum::class)->reveal();
    }

    public function testWillExtendRequiresClass(): void
    {
        $this->expectException(\Prophecy\Exception\Doubler\ClassNotFoundException::class);
        (new \Prophecy\Prophet())->prophesize()->willExtend(Wave8RuntimeMarker::class);
    }

    public function testWillImplementRequiresInterface(): void
    {
        $this->expectException(\Prophecy\Exception\Doubler\InterfaceNotFoundException::class);
        (new \Prophecy\Prophet())->prophesize()->willImplement(Wave8RuntimeBase::class);
    }

    public function testFinalMethodCannotBeConfigured(): void
    {
        $prophecy = (new \Prophecy\Prophet())->prophesize(Wave8RuntimeRestricted::class);
        $this->expectException(\Prophecy\Exception\Prophecy\MethodProphecyException::class);
        $prophecy->fixed();
    }

    public function testPrivateMethodCannotInvokeItsConfiguredPromise(): void
    {
        $prophecy = (new \Prophecy\Prophet())->prophesize(Wave8RuntimeRestricted::class);
        $prophecy->privateMethod()->willReturn('configured');
        $this->expectException(\Prophecy\Exception\Doubler\MethodNotFoundException::class);
        $prophecy->reveal()->privateMethod();
    }

    public function testIntersectionReturnCannotBeConfigured(): void
    {
        $prophecy = (new \Prophecy\Prophet())->prophesize(Wave8RuntimeRestricted::class);
        $this->expectException(\Prophecy\Exception\Prophecy\MethodProphecyException::class);
        $prophecy->intersection();
    }

    public function testAbstractProtectedAndMagicMethodsCanBeConfigured(): void
    {
        $prophecy = (new \Prophecy\Prophet())->prophesize(Wave8RuntimeRestricted::class);
        self::assertInstanceOf(MethodProphecy::class, $prophecy->abstractProtected());
        $prophecy->__toString()->willReturn('configured');
        self::assertSame('configured', (string) $prophecy->reveal());
    }

    public function testUnderscoreMethodKeepsItsOriginalImplementation(): void
    {
        $prophecy = (new \Prophecy\Prophet())->prophesize(Wave8RuntimeRestricted::class);
        $prophecy->_hidden()->willReturn('configured');
        self::assertSame('hidden', $prophecy->reveal()->_hidden());
    }

    public function testStaticMethodCannotInvokeItsConfiguredPromise(): void
    {
        $prophecy = (new \Prophecy\Prophet())->prophesize(Wave8RuntimeRestricted::class);
        $prophecy->staticMethod()->willReturn('configured');
        $this->expectException(\Error::class);
        $prophecy->reveal()->staticMethod();
    }

    public function testConcreteProtectedMethodCannotBeInvokedPublicly(): void
    {
        $prophecy = (new \Prophecy\Prophet())->prophesize(Wave8RuntimeRestricted::class);
        $prophecy->protectedMethod()->willReturn('configured');
        $this->expectException(\Prophecy\Exception\Doubler\MethodNotFoundException::class);
        $prophecy->reveal()->protectedMethod();
    }

    public function testWrongReturnValueFailsAtRuntime(): void
    {
        $service = $this->prophesize(Wave8RuntimeService::class);
        $service->name(1)->willReturn(new \stdClass());
        $this->expectException(\TypeError::class);
        $service->reveal()->name(1);
    }
}
