<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\Assertion\ArrayKeyAssertion;
use Mago\Sdk\Analyzer\Assertion\ArrayKeyAssertionKind;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\MethodAssertionProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;

/**
 * After assertArrayHasKey() with a literal key, that key exists on the array argument.
 *
 * Non-literal keys contribute no fact, so a variable key cannot narrow a different index.
 */
final class AssertArrayHasKeyAssertion implements MethodAssertionProvider
{
    public function getTargets(): array
    {
        return [
            MethodTarget::exact('PHPUnit\\Framework\\Assert', 'assertArrayHasKey'),
        ];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $type = $context->invocation->getArgument(0, 'key')?->type;
        if (!$type instanceof Type) {
            return null;
        }

        $key = self::literalKey($type);
        if ($key === null) {
            return null;
        }

        return new InvocationAssertions(
            assertions: [
                '$array' => [
                    new ArrayKeyAssertion(ArrayKeyAssertionKind::HasKey, $key),
                ],
            ],
        );
    }

    private static function literalKey(Type $type): ?ArrayKey
    {
        $string = $type->getLiteralString();
        if ($string !== null) {
            return new ArrayKey(ArrayKeyKind::String, $string);
        }

        $integer = $type->getLiteralInt();
        if ($integer !== null) {
            return new ArrayKey(ArrayKeyKind::Integer, $integer);
        }

        return null;
    }
}
