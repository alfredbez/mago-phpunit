<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\ClassInitializerProvider;
use Mago\Sdk\Analyzer\ClassInitializerProviderContext;
use Mago\Sdk\Analyzer\ClassTarget;

/**
 * PHPUnit runs setUp() and Before hooks before every test method.
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
        $initializers = [];
        if ($context->codebase->methodExists($context->class->name, 'setUp')) {
            $initializers[] = 'setUp';
        }

        foreach ($context->class->methods as $name) {
            $method = $context->codebase->getMethod($context->class->name, $name);
            foreach ($method?->attributes ?? [] as $attribute) {
                if (strtolower($attribute->name) === 'phpunit\\framework\\attributes\\before') {
                    $initializers[] = $name;
                    break;
                }
            }
        }

        return array_values(array_unique($initializers));
    }
}
