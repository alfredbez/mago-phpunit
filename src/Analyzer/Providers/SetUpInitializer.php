<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\ClassInitializerProvider;
use Mago\Sdk\Analyzer\ClassInitializerProviderContext;
use Mago\Sdk\Analyzer\ClassTarget;

/**
 * PHPUnit runs setUp() before every test method, so assignments there initialize properties.
 */
final class SetUpInitializer implements ClassInitializerProvider
{
    public function getTargets(): array
    {
        return [
            ClassTarget::exact('PHPUnit\\Framework\\TestCase'),
        ];
    }

    public function getClassInitializers(ClassInitializerProviderContext $context): array
    {
        if (!$context->codebase->methodExists($context->class->name, 'setUp')) {
            return [];
        }

        return ['setUp'];
    }
}
