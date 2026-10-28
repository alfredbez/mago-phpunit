<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\FunctionAssertionProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InvocationAssertions;

final class AssertArrayHasKeyFunctionAssertion implements FunctionAssertionProvider
{
    public function __construct(
        private readonly AssertArrayHasKeyAssertion $assertion = new AssertArrayHasKeyAssertion(),
    ) {}

    public function getTargets(): array
    {
        return [FunctionTarget::exact('PHPUnit\\Framework\\assertArrayHasKey')];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        return $this->assertion->getAssertions($context);
    }
}
