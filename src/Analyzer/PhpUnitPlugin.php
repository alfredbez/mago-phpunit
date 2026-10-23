<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer;

use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertArrayHasKeyAssertion;
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
            description: 'Narrows PHPUnit assertions and treats setUp() as a test initializer.',
            defaultEnabled: true,
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerMethodAssertionProvider(new AssertArrayHasKeyAssertion());
        $registry->registerClassInitializerProvider(new SetUpInitializer());
    }
}
