<?php

declare(strict_types=1);
namespace AlfredBez\MagoPhpunit\Analyzer\Providers;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
/** @internal */
final class LegacyMockReturnTypeProvider implements MethodReturnTypeProvider
{
    public function getTargets(): array
    {
        return [MethodTarget::anyClass('expects'), MethodTarget::anyClass('method')];
    }
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return LegacyMockCalls::isLegacyUnion($context->invocation->receiverType)
            ? Type::namedObject('PHPUnit\\Framework\\MockObject\\Builder\\InvocationMocker')
            : null;
    }
}
