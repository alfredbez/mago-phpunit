<?php

declare(strict_types=1);

namespace Wave8\Prophecy;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\Prophecy\MethodProphecy;

interface Service
{
    public function find(int $id): string;
    public function reset(): void;
    public function neverReturns(): never;
    public function objectArgument(\stdClass $value): string;
    public function same(string $name): string;
    public function sequence(): \Generator;
    public function notGenerator(): string;
    public function nullable(): ?string;
    public function narrowed(\stdClass|string $value): string;
}
interface Other {}
abstract class RestrictedService
{
    final public function fixed(): string { return 'fixed'; }
    private function privateMethod(): string { return 'private'; }
    protected function protectedMethod(): string { return 'protected'; }
    abstract protected function abstractProtected(): string;
    public static function staticMethod(): string { return 'static'; }
    public function _hidden(): string { return 'hidden'; }
    public function intersection(): Service&Other { throw new \LogicException(); }
    public function __toString(): string { return 'magic'; }
}
final class FinalService {}
trait ServiceTrait {}
enum State { case Ready; }

final class ProphecyTest extends TestCase
{
    use ProphecyTrait;

    public function testContracts(): void
    {
        $service = $this->prophesize(Service::class);
        $service->find(Argument::type('int'))->willReturn('found');
        $service->find(Argument::type('string'))->willReturn('found');
        $service->find(Argument::exact('bad'))->willReturn('found');
        $service->find(Argument::exact(1))->willReturn('found');
        $service->find(Argument::exact('42'))->willReturn('numeric');
        $service->find(Argument::exact(42.0))->willReturn('numeric');
        $service->nullable()->willReturnArgument(9);
        $service->find(1)->willReturn(123);
        $service->find(1)->willReturn(new \stdClass());
        $service->reset()->willReturn(null);
        $service->find(1)->willReturn();
        $service->same('x')->willReturnArgument(0);
        $service->find(1)->willReturnArgument(0);
        $service->same('x')->willReturnArgument(4);
        $service->objectArgument(new \stdClass())->willReturnArgument(0);
        $service->neverReturns()->willReturn('bad');
        $service->reset()->willYield([]);
        $service->sequence()->willYield(['ok']);
        $service->sequence()->willYield('bad');
        $service->same('x')->willReturnArgument('bad');
        $service->notGenerator()->willYield(['bad']);
        $this->prophesize(FinalService::class);
        $this->prophesize(ServiceTrait::class);
        $this->prophesize(State::class);
        (new \Prophecy\Prophet())->prophesize(FinalService::class);
        $service->willExtend(FinalService::class);
        $service->willImplement(ServiceTrait::class);
        Argument::type('no_such_primitive');
        Argument::type('integer');
        $restricted = $this->prophesize(RestrictedService::class);
        $restricted->fixed();
        $restricted->privateMethod();
        $restricted->protectedMethod();
        $restricted->staticMethod();
        $restricted->_hidden();
        $restricted->intersection();
        $restricted->abstractProtected();
        $restricted->__toString();
        $service->willExtend(Service::class);
        $service->willImplement(RestrictedService::class);
    }

    public function heldPromises(): void
    {
        $service = $this->prophesize(Service::class);
        $return = $service->find(42);
        $return->willReturn('valid');
        $return->willReturn(new \stdClass());
        $argument = $service->objectArgument(new \stdClass());
        $argument->willReturnArgument(0);
        $yield = $service->notGenerator();
        $yield->willYield([]);
    }

    public function heldNarrowedArguments(\stdClass|string $dynamic): void
    {
        $service = $this->prophesize(Service::class);
        $valid = $service->narrowed('valid');
        $valid->willReturnArgument(0);
        $invalid = $service->narrowed(new \stdClass());
        $invalid->willReturnArgument(0);
        $unresolved = $service->narrowed($dynamic);
        $unresolved->willReturnArgument(0);
        $token = $service->narrowed(Argument::any());
        $token->willReturnArgument(0);
        $arguments = ['valid'];
        $unpacked = $service->narrowed(...$arguments);
        $unpacked->willReturnArgument(0);
        $service->narrowed('fluent')->willReturnArgument(0);
    }

    /** @param list<mixed> $arguments */
    public function dynamicPromises(array $arguments, string $method): void
    {
        $service = $this->prophesize(Service::class);
        $service->find(42)->willReturn(...$arguments);
        $service->{$method}(...$arguments);
    }

    /** @param Service|ObjectProphecy $legacy */
    public function unionWarning(Service|ObjectProphecy $legacy): void
    {
        $legacy->find(1);
    }

    public function standaloneType(): Service
    {
        $prophecy = $this->prophesize();
        $prophecy->willImplement(Service::class);
        return $prophecy->reveal();
    }

    public function chainedIntersection(): Service&Other
    {
        return $this->prophesize(Service::class)->willImplement(Other::class)->reveal();
    }

    public function typedSetup(): MethodProphecy
    {
        return $this->prophesize(Service::class)->find(3);
    }

    public function typedReveal(): Service
    {
        return $this->prophesize(Service::class)->reveal();
    }

    public function typedTraversable(): \Iterator
    {
        return $this->prophesize(\Traversable::class)->reveal();
    }

    public function prophetFactory(): Service
    {
        return (new \Prophecy\Prophet())->prophesize(Service::class)->reveal();
    }

    public function intersectionSetup(): MethodProphecy
    {
        return $this->prophesize(Service::class)->willImplement(Other::class)->find(3);
    }

    public function fluentType(): Service
    {
        return $this->prophesize()->willImplement(Service::class)->reveal();
    }
}

class OldParent {}
class NewParent {}
final class ParentReplacementTest extends TestCase
{
    use ProphecyTrait;
    public function wrongChain(): OldParent
    {
        return $this->prophesize(OldParent::class)->willExtend(NewParent::class)->reveal(); // wrong-chain
    }
    public function rightChain(): NewParent
    {
        return $this->prophesize(OldParent::class)->willExtend(NewParent::class)->reveal(); // right-chain
    }
    public function wrongVariable(): OldParent
    {
        $p = $this->prophesize(OldParent::class);
        $p->willExtend(NewParent::class);
        return $p->reveal(); // wrong-variable
    }
    public function rightVariable(): NewParent&Other
    {
        $p = $this->prophesize(OldParent::class);
        $p->willImplement(Other::class);
        $p->willExtend(NewParent::class);
        return $p->reveal(); // right-variable
    }
}
