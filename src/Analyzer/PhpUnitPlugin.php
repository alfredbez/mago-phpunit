<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer;

use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertArrayHasKeyAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertArrayHasKeyFunctionAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ContainsOnlyInstancesAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\DataProviderArguments;
use AlfredBez\MagoPhpunit\Analyzer\Providers\SetUpInitializer;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

final class PhpUnitPlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            identifier: 'alfredbez/phpunit',
            name: 'PHPUnit',
            description: 'Narrows PHPUnit assertions, treats setUp() as a test initializer, and checks data-provider rows.',
            defaultEnabled: true,
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerMethodAssertionProvider(new AssertArrayHasKeyAssertion());
        $registry->registerFunctionAssertionProvider(new AssertArrayHasKeyFunctionAssertion());
        $registry->registerMethodAssertionProvider(new ContainsOnlyInstancesAssertion());
        $registry->registerClassInitializerProvider(new SetUpInitializer());
        $registry->registerClassLikeAnalysisHook(new DataProviderArguments());
    }
}
