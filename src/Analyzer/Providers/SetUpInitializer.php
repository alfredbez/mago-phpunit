<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\ClassInitializerProvider;
use Mago\Sdk\Analyzer\ClassInitializerProviderContext;
use Mago\Sdk\Analyzer\ClassTarget;

/**
 * PHPUnit runs setUp() and Before hooks before every test method.
 *
 * @internal
 */
final class SetUpInitializer implements ClassInitializerProvider
{
    private const TEST_CASE = 'PHPUnit\\Framework\\TestCase';

    private const BEFORE_ATTRIBUTE = 'PHPUnit\\Framework\\Attributes\\Before';

    public function getTargets(): array
    {
        return [
            ClassTarget::exact(self::TEST_CASE),
        ];
    }

    public function getClassInitializers(ClassInitializerProviderContext $context): array
    {
        $initializers = [];
        $hasSetUp = false;
        foreach ($context->class->parentClasses as $parent) {
            if (strtolower($parent) === 'phpunit\\framework\\testcase') {
                $hasSetUp = true;
                break;
            }
        }
        if ($hasSetUp || $context->codebase->methodExists($context->class->name, 'setUp')) {
            $initializers[] = 'setUp';
        }

        $beforeMethods = $context->codebase->findMethods(
            descendantsOf: self::TEST_CASE,
            withAnyAttribute: [self::BEFORE_ATTRIBUTE],
            fields: 0,
        );
        $classKey = strtolower($context->class->name);
        foreach ($beforeMethods as $method) {
            if (strtolower($method->method->class) === $classKey && $method->method->member !== '') {
                $initializers[] = $method->method->member;
            }
        }

        return array_values(array_unique($initializers));
    }
}
