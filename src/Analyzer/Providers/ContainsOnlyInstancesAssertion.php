<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\MethodAssertionProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\IterableType;

/**
 * assertContainsOnlyInstancesOf(Foo::class, $items) means $items is an iterable of Foo.
 *
 * PHPUnit 11.5 and later document this with @phpstan-assert, which Mago already understands.
 * PHPUnit 10.5 does not, so the extension supplies the same fact only when the
 * installed Assert method or function carries no assertion of its own for $haystack.
 *
 * @internal
 */
final class ContainsOnlyInstancesAssertion implements MethodAssertionProvider
{
    public function getTargets(): array
    {
        return [
            MethodTarget::exact('PHPUnit\\Framework\\Assert', 'assertContainsOnlyInstancesOf'),
        ];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $callable = $context->invocation->kind === InvocationKind::Function
            ? $context->codebase->getFunction('PHPUnit\\Framework\\assertContainsOnlyInstancesOf')
            : $context->codebase->getMethod('PHPUnit\\Framework\\Assert', 'assertContainsOnlyInstancesOf');
        if (($callable?->assertions['$haystack'] ?? []) !== []) {
            return null;
        }

        $class = $context->invocation->getArgument(0, 'className')?->type?->getLiteralClassString();
        $haystackArg = $context->invocation->getArgument(1, 'haystack');
        if ($class === null || $haystackArg === null || !PhpUnitMethodAssertions::isNarrowableExpression($haystackArg->expression)) {
            return null;
        }

        return new InvocationAssertions(
            assertions: [
                '$haystack' => [
                    new TypeAssertion(
                        TypeAssertionKind::IsType,
                        Type::fromAtomic(new IterableType(Type::mixed(), Type::namedObject($class), null)),
                    ),
                ],
            ],
        );
    }
}
