<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\FunctionAssertionProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InvocationAssertions;

/** @internal */
final class ContainsOnlyInstancesFunctionAssertion implements FunctionAssertionProvider
{
    public function __construct(
        private readonly ContainsOnlyInstancesAssertion $assertion = new ContainsOnlyInstancesAssertion(),
    ) {}

    public function getTargets(): array
    {
        return [FunctionTarget::exact('PHPUnit\\Framework\\assertContainsOnlyInstancesOf')];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        return $this->assertion->getAssertions($context);
    }
}
