<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;

/** @internal */
final class ProphecyReturnTypeProvider implements MethodReturnTypeProvider
{
    public function getTargets(): array
    {
        return (new ProphecySupport())->getTargets();
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return (new ProphecySupport())->getReturnType($context);
    }
}
