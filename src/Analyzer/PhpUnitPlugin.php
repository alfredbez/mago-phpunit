<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer;

use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertArrayHasKeyAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertArrayHasKeyFunctionAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertionQualityFunctionHook;
use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertionQualityHook;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ContainsOnlyInstancesAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ContainsOnlyInstancesFunctionAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\CoverageTargetsHook;
use AlfredBez\MagoPhpunit\Analyzer\Providers\DataProviderArguments;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ExactArrayCountReturnTypeProvider;
use AlfredBez\MagoPhpunit\Analyzer\Providers\IntersectionMockReturnTypeProvider;
use AlfredBez\MagoPhpunit\Analyzer\Providers\LegacyMockCalls;
use AlfredBez\MagoPhpunit\Analyzer\Providers\LegacyMockIssueFilter;
use AlfredBez\MagoPhpunit\Analyzer\Providers\LegacyMockReturnTypeProvider;
use AlfredBez\MagoPhpunit\Analyzer\Providers\LegacyMockSourceIndex;
use AlfredBez\MagoPhpunit\Analyzer\Providers\MockBuilderAndExpectationHook;
use AlfredBez\MagoPhpunit\Analyzer\Providers\MockMethodCallHook;
use AlfredBez\MagoPhpunit\Analyzer\Providers\PhpUnitFunctionAssertions;
use AlfredBez\MagoPhpunit\Analyzer\Providers\PhpUnitMethodAssertions;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ProphecyMutationAssertions;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ProphecyReturnTypeProvider;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ProphecySupport;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ResetCachesHook;
use AlfredBez\MagoPhpunit\Analyzer\Providers\SetUpInitializer;
use AlfredBez\MagoPhpunit\Analyzer\Providers\TestExceptionFilter;
use AlfredBez\MagoPhpunit\Analyzer\Providers\TestMetadataHook;
use AlfredBez\MagoPhpunit\Analyzer\Providers\TraitDoubleReturnTypeProvider;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\ClassLikeAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** @internal */
final class PhpUnitPlugin implements Plugin
{
    private const TEST_CASE = 'PHPUnit\\Framework\\TestCase';

    private const LIFECYCLE_ATTRIBUTES = [
        'PHPUnit\\Framework\\Attributes\\Test',
        'PHPUnit\\Framework\\Attributes\\Before',
        'PHPUnit\\Framework\\Attributes\\After',
        'PHPUnit\\Framework\\Attributes\\BeforeClass',
        'PHPUnit\\Framework\\Attributes\\AfterClass',
        'PHPUnit\\Framework\\Attributes\\PreCondition',
        'PHPUnit\\Framework\\Attributes\\PostCondition',
    ];

    private const LIFECYCLE_METHODS = [
        'setUp',
        'tearDown',
        'setUpBeforeClass',
        'tearDownAfterClass',
        'assertPreConditions',
        'assertPostConditions',
    ];

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
        $legacySource = new LegacyMockSourceIndex();
        $prophecy = new ProphecySupport();
        $assertionQuality = new AssertionQualityHook();
        $assertionQualitySame = new AssertionQualityHook(sameOnly: true);
        $assertionQualityFunctions = new AssertionQualityFunctionHook();
        $mockBuilderHook = new MockBuilderAndExpectationHook();

        $resetters = [
            $legacyMocks->reset(...),
            $legacySource->reset(...),
            $assertionQuality->reset(...),
            $assertionQualitySame->reset(...),
            $assertionQualityFunctions->reset(...),
            $mockBuilderHook->reset(...),
        ];
        if ($dataProviders instanceof DataProviderArguments) {
            $resetters[] = $dataProviders->reset(...);
        }

        $registry->registerBeforeAnalysisHook(new ResetCachesHook($resetters));
        foreach (self::LIFECYCLE_ATTRIBUTES as $attribute) {
            $registry->registerAttributedEntryPoint(self::TEST_CASE, $attribute);
        }
        foreach (self::LIFECYCLE_METHODS as $method) {
            $registry->registerEntryPoint(MethodTarget::exact(self::TEST_CASE, $method));
        }

        $registry->registerMethodReturnTypeProvider(new ProphecyReturnTypeProvider());
        $registry->registerMethodCallAnalysisHook($prophecy);
        $registry->registerMethodAssertionProvider(new ProphecyMutationAssertions());
        $registry->registerMethodCallAnalysisHook($assertionQuality);
        $registry->registerMethodCallAnalysisHook($assertionQualitySame);
        $registry->registerNodeAnalysisHook($assertionQualityFunctions);
        $registry->registerClassLikeAnalysisHook(new TestMetadataHook());
        $registry->enableProviderMemoization();
        $registry->registerMethodAssertionProvider(new AssertArrayHasKeyAssertion());
        $registry->registerFunctionAssertionProvider(new AssertArrayHasKeyFunctionAssertion());
        $registry->registerMethodAssertionProvider(new PhpUnitMethodAssertions());
        $registry->registerFunctionAssertionProvider(new PhpUnitFunctionAssertions());
        $registry->registerFunctionReturnTypeProvider(new ExactArrayCountReturnTypeProvider());
        $registry->registerMethodAssertionProvider(new ContainsOnlyInstancesAssertion());
        $registry->registerFunctionAssertionProvider(new ContainsOnlyInstancesFunctionAssertion());
        $registry->registerMethodReturnTypeProvider(new IntersectionMockReturnTypeProvider());
        $registry->registerMethodReturnTypeProvider(new TraitDoubleReturnTypeProvider());
        $registry->registerMethodReturnTypeProvider(new LegacyMockReturnTypeProvider());
        $registry->registerNodeAnalysisHook($legacyMocks);
        $registry->registerIssueFilterHook(new LegacyMockIssueFilter($legacySource));
        $registry->registerIssueFilterHook(new TestExceptionFilter());
        $registry->registerClassInitializerProvider(new SetUpInitializer());
        $registry->registerClassLikeAnalysisHook($dataProviders);
        $registry->registerClassLikeAnalysisHook(new CoverageTargetsHook());
        $registry->registerMethodCallAnalysisHook(new MockMethodCallHook());
        $registry->registerMethodCallAnalysisHook($mockBuilderHook);
        $registry->registerAfterAnalysisHook($dataProviders);
    }
}
