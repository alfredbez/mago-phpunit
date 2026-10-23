<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\MethodAssertionProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\IterableType;

/**
 * assertContainsOnlyInstancesOf(Foo::class, $items) means $items is an iterable of Foo.
 *
 * PHPUnit 12 documents this with @phpstan-assert, which Mago already understands.
 * PHPUnit 10.5 does not, so the extension supplies the same fact.
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
        $class = $context->invocation->getArgument(0, 'className')?->type?->getLiteralClassString();
        if ($class === null) {
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
