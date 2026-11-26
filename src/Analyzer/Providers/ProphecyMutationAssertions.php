<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\MethodAssertionProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** @internal */
final class ProphecyMutationAssertions implements MethodAssertionProvider
{
    public function getTargets(): array
    {
        return [MethodTarget::exact('Prophecy\\Prophecy\\ObjectProphecy', 'willExtend'), MethodTarget::exact('Prophecy\\Prophecy\\ObjectProphecy', 'willImplement')];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $type = (new ProphecySupport())->getReturnType(new ReturnTypeProviderContext($context->phpVersion, $context->codebase, $context->invocation, $context->types, $context->cancellation));
        $receiver = $context->invocation->receiverType;
        if ($type === null || $receiver === null || count($receiver->atomicTypes) !== 1) {
            return null;
        }
        $current = $receiver->atomicTypes[0];
        $next = $type->atomicTypes[0];
        if (!$current instanceof NamedObjectType || !$next instanceof NamedObjectType || !isset($next->parameters[0])) {
            return null;
        }
        $state = Type::namedObject('Prophecy\\Prophecy\\ObjectProphecy', $current->parameters[0] ?? Type::object(), $next->parameters[0]);
        return new InvocationAssertions(assertions: ['$this' => [new TypeAssertion(TypeAssertionKind::IsType, $state)]]);
    }
}
