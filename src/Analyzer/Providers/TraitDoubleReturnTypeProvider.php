<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

final class TraitDoubleReturnTypeProvider implements MethodReturnTypeProvider
{
    public function getTargets(): array
    {
        return [
            MethodTarget::exact('PHPUnit\\Framework\\TestCase', 'getMockForTrait'),
            MethodTarget::exact('PHPUnit\\Framework\\TestCase', 'getObjectForTrait'),
        ];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $argument = $context->invocation->getArgument(0, 'traitName');
        if ($argument === null || $argument->unpacked || $argument->placeholder) {
            return null;
        }
        $name = $argument->type?->getLiteralClassString() ?? $argument->type?->getLiteralString();
        if ($name === null || !$context->codebase->traitExists($name)) {
            return null;
        }

        return Type::fromAtomic(new NamedObjectType(
            name: $name,
            parameters: null,
            variances: null,
            static: false,
            isThis: false,
            intersections: strtolower($context->invocation->name) === 'getmockfortrait'
                ? [new NamedObjectType('PHPUnit\\Framework\\MockObject\\MockObject', null, null, false, false, null, false)]
                : null,
            remappedParameters: false,
        ));
    }
}
