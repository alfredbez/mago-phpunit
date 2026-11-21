<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\FunctionAssertionProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InvocationAssertions;

final class PhpUnitFunctionAssertions implements FunctionAssertionProvider
{
    public function __construct(
        private readonly PhpUnitMethodAssertions $assertions = new PhpUnitMethodAssertions(),
    ) {}

    public function getTargets(): array
    {
        $targets = [];
        foreach (PhpUnitMethodAssertions::METHODS as $method) {
            $targets[] = FunctionTarget::exact('PHPUnit\\Framework\\' . $method);
        }

        return $targets;
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        return $this->assertions->getAssertions($context);
    }
}
