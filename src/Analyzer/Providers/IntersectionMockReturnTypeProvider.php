<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/**
 * Narrows TestCase::createMockForIntersectionOfInterfaces() and
 * TestCase::createStubForIntersectionOfInterfaces() when passed a literal
 * non-optional list of at least two distinct existing interfaces.
 *
 * @internal
 */
final class IntersectionMockReturnTypeProvider implements MethodReturnTypeProvider
{
    private const TEST_CASE = 'PHPUnit\\Framework\\TestCase';
    private const MOCK_OBJECT = 'PHPUnit\\Framework\\MockObject\\MockObject';
    private const STUB = 'PHPUnit\\Framework\\MockObject\\Stub';

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::TEST_CASE, 'createMockForIntersectionOfInterfaces'),
            MethodTarget::exact(self::TEST_CASE, 'createStubForIntersectionOfInterfaces'),
        ];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $arg = $context->invocation->getArgument(0, 'interfaces');
        if ($arg === null || $arg->unpacked || $arg->placeholder || !$arg->type instanceof Type) {
            return null;
        }

        $interfaces = self::extractInterfaces($arg->type, $context->codebase);
        if ($interfaces === null || count($interfaces) < 2) {
            return null;
        }

        $name = $context->invocation->name;
        $short = str_contains($name, '\\') ? substr($name, strrpos($name, '\\') + 1) : $name;
        $baseDouble = strtolower($short) === 'createstubforintersectionofinterfaces'
            ? self::STUB
            : self::MOCK_OBJECT;

        $primary = array_shift($interfaces);
        $intersections = [];
        foreach ($interfaces as $extra) {
            $intersections[] = new NamedObjectType($extra, null, null, false, false, null, false);
        }
        $intersections[] = new NamedObjectType($baseDouble, null, null, false, false, null, false);

        return Type::fromAtomic(new NamedObjectType(
            name: $primary,
            parameters: null,
            variances: null,
            static: false,
            isThis: false,
            intersections: $intersections,
            remappedParameters: false,
        ));
    }

    /**
     * @return null|non-empty-list<string>
     */
    private static function extractInterfaces(Type $type, Codebase $codebase): ?array
    {
        if (count($type->atomicTypes) !== 1) {
            return null;
        }

        $atomic = $type->atomicTypes[0];
        $elementTypes = [];

        if ($atomic instanceof ListType) {
            if ($atomic->knownElements === null || $atomic->knownCount !== count($atomic->knownElements)) {
                return null;
            }
            foreach ($atomic->knownElements as $index => $element) {
                if ($element->optional || $element->index !== $index) {
                    return null;
                }
                $elementTypes[] = $element->type;
            }
        } elseif ($atomic instanceof KeyedArrayType) {
            if ($atomic->knownItems === null || $atomic->keyType !== null || $atomic->valueType !== null) {
                return null;
            }
            foreach ($atomic->knownItems as $index => $item) {
                if ($item->optional || $item->key->kind !== ArrayKeyKind::Integer || $item->key->value !== $index) {
                    return null;
                }
                $elementTypes[] = $item->type;
            }
        } else {
            return null;
        }

        if (count($elementTypes) < 2) {
            return null;
        }

        $names = [];
        $seen = [];
        foreach ($elementTypes as $elType) {
            $literal = $elType->getLiteralClassString() ?? $elType->getLiteralString();
            if ($literal === null) {
                return null;
            }
            $normalized = ltrim($literal, '\\');
            if ($normalized === '') {
                return null;
            }
            $lower = strtolower($normalized);
            if (isset($seen[$lower])) {
                return null;
            }
            $seen[$lower] = true;
            $names[] = $normalized;
        }

        foreach ($codebase->checkMultipleInterfacesExist($names) as $exists) {
            if (!$exists) {
                return null;
            }
        }

        return $names;
    }
}
