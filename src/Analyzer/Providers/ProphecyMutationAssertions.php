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

final class ProphecyMutationAssertions implements MethodAssertionProvider
{
    public function getTargets(): array
    {
        return [MethodTarget::exact('Prophecy\\Prophecy\\ObjectProphecy', 'willExtend'), MethodTarget::exact('Prophecy\\Prophecy\\ObjectProphecy', 'willImplement')];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $type = (new ProphecySupport())->getReturnType(new ReturnTypeProviderContext($context->phpVersion, $context->codebase, $context->invocation, $context->types, $context->cancellation));
        return $type === null ? null : new InvocationAssertions(assertions: ['$this' => [new TypeAssertion(TypeAssertionKind::IsType, $type)]]);
    }
}
