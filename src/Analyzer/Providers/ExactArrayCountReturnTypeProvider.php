<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;

/** @internal */
final class ExactArrayCountReturnTypeProvider implements FunctionReturnTypeProvider
{
    public function getTargets(): array
    {
        return [FunctionTarget::exact('count')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        foreach ($context->invocation->arguments as $argument) {
            if ($argument->unpacked) {
                return null;
            }
        }
        $mode = $context->invocation->getArgument(1, 'mode');
        if ($mode !== null && $mode->type?->getLiteralInt() !== 0) {
            return null;
        }
        $value = $context->invocation->getArgument(0, 'value')?->type;
        if ($value === null) {
            return null;
        }

        $counts = [];
        foreach ($value->atomicTypes as $atomic) {
            if ($atomic instanceof ListType && $atomic->knownCount !== null) {
                $count = $atomic->knownCount;
            } elseif (
                $atomic instanceof ListType && count($atomic->elementType->atomicTypes) === 1
                && $atomic->elementType->atomicTypes[0] instanceof SimpleAtomicType
                && $atomic->elementType->atomicTypes[0]->kind === SimpleAtomicTypeKind::Never
            ) {
                $count = 0;
            } elseif ($atomic instanceof KeyedArrayType && $atomic->valueType === null && $atomic->knownItems !== null) {
                foreach ($atomic->knownItems as $item) {
                    if ($item->optional) {
                        return null;
                    }
                }
                $count = count($atomic->knownItems);
            } else {
                return null;
            }
            $counts[$count] = Type::literalInt($count);
        }
        $counts = array_values($counts);

        return count($counts) === 1 ? $counts[0] : Type::union(...$counts);
    }
}
