<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer;

use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertArrayHasKeyAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertArrayHasKeyFunctionAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ContainsOnlyInstancesAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\CoverageTargetsHook;
use AlfredBez\MagoPhpunit\Analyzer\Providers\DataProviderArguments;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ExactArrayCountReturnTypeProvider;
use AlfredBez\MagoPhpunit\Analyzer\Providers\IntersectionMockReturnTypeProvider;
use AlfredBez\MagoPhpunit\Analyzer\Providers\MockBuilderAndExpectationHook;
use AlfredBez\MagoPhpunit\Analyzer\Providers\MockMethodCallHook;
use AlfredBez\MagoPhpunit\Analyzer\Providers\PhpUnitFunctionAssertions;
use AlfredBez\MagoPhpunit\Analyzer\Providers\PhpUnitMethodAssertions;
use AlfredBez\MagoPhpunit\Analyzer\Providers\SetUpInitializer;
use AlfredBez\MagoPhpunit\Analyzer\Providers\TraitDoubleReturnTypeProvider;
use AlfredBez\MagoPhpunit\Analyzer\Providers\LegacyMockCalls;
use AlfredBez\MagoPhpunit\Analyzer\Providers\TestExceptionFilter;
use Mago\Sdk\Analyzer\ClassLikeAnalysisHook;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

final class PhpUnitPlugin implements Plugin
{
    public function __construct(private readonly ClassLikeAnalysisHook&AfterAnalysisHook $dataProviders = new DataProviderArguments()) {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            identifier: 'alfredbez/phpunit',
            name: 'PHPUnit',
            description: 'Narrows PHPUnit assertions and intersection mocks, recognizes setUp() and Before initializers, and checks data-provider rows, coverage targets, and mocked method names.',
            defaultEnabled: true,
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $dataProviders = $this->dataProviders;
        $legacyMocks = new LegacyMockCalls();
        $registry->enableProviderMemoization();
        $registry->registerMethodAssertionProvider(new AssertArrayHasKeyAssertion());
        $registry->registerFunctionAssertionProvider(new AssertArrayHasKeyFunctionAssertion());
        $registry->registerMethodAssertionProvider(new PhpUnitMethodAssertions());
        $registry->registerFunctionAssertionProvider(new PhpUnitFunctionAssertions());
        $registry->registerFunctionReturnTypeProvider(new ExactArrayCountReturnTypeProvider());
        $registry->registerMethodAssertionProvider(new ContainsOnlyInstancesAssertion());
        $registry->registerMethodReturnTypeProvider(new IntersectionMockReturnTypeProvider());
        $registry->registerMethodReturnTypeProvider(new TraitDoubleReturnTypeProvider());
        $registry->registerMethodReturnTypeProvider(new \AlfredBez\MagoPhpunit\Analyzer\Providers\LegacyMockReturnTypeProvider());
        $registry->registerNodeAnalysisHook($legacyMocks);
        $registry->registerIssueFilterHook(new \AlfredBez\MagoPhpunit\Analyzer\Providers\LegacyMockIssueFilter());
        $registry->registerIssueFilterHook(new TestExceptionFilter());
        $registry->registerClassInitializerProvider(new SetUpInitializer());
        $registry->registerClassLikeAnalysisHook($dataProviders);
        $registry->registerClassLikeAnalysisHook(new CoverageTargetsHook());
        $registry->registerMethodCallAnalysisHook(new MockMethodCallHook());
        $registry->registerMethodCallAnalysisHook(new MockBuilderAndExpectationHook());
        $registry->registerAfterAnalysisHook($dataProviders);
    }
}
