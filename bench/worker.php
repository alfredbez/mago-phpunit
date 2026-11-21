<?php

declare(strict_types=1);

use AlfredBez\MagoPhpunit\Analyzer\PhpUnitPlugin;
use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertArrayHasKeyAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\AssertArrayHasKeyFunctionAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\ContainsOnlyInstancesAssertion;
use AlfredBez\MagoPhpunit\Analyzer\Providers\DataProviderArguments;
use AlfredBez\MagoPhpunit\Analyzer\Providers\SetUpInitializer;
use Mago\Sdk\Analyzer\ClassLikeAnalysisHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';

$hook = new class implements ClassLikeAnalysisHook {
    private DataProviderArguments $provider;
    private WeakMap $snapshots;
    public int $nanoseconds = 0;
    public int $calls = 0;

    public function __construct()
    {
        $this->provider = new DataProviderArguments();
        $this->snapshots = new WeakMap();
    }

    public function getTargets(): array
    {
        return $this->provider->getTargets();
    }

    public function getRequirements(): array
    {
        return $this->provider->getRequirements();
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $start = hrtime(true);
        $this->provider->analyze($context);
        $this->nanoseconds += hrtime(true) - $start;
        $this->calls++;

        $directory = getenv('BENCH_SNAPSHOT_DIR');
        if ($directory !== false && !isset($this->snapshots[$context->source])) {
            $this->snapshots[$context->source] = true;
            $source = $context->analysis->getSourceFile();
            file_put_contents($directory . '/' . basename($source->path) . '.snapshot', serialize($source));
        }
    }
};

$plugin = new class($hook) implements Plugin {
    public function __construct(private ClassLikeAnalysisHook $hook) {}

    public function getDefinition(): PluginDefinition
    {
        return (new PhpUnitPlugin())->getDefinition();
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerMethodAssertionProvider(new AssertArrayHasKeyAssertion());
        $registry->registerFunctionAssertionProvider(new AssertArrayHasKeyFunctionAssertion());
        $registry->registerMethodAssertionProvider(new ContainsOnlyInstancesAssertion());
        $registry->registerClassInitializerProvider(new SetUpInitializer());
        $registry->registerClassLikeAnalysisHook($this->hook);
    }
};

register_shutdown_function(static function () use ($hook): void {
    $path = getenv('BENCH_PROFILE_PATH');
    if ($path !== false) {
        file_put_contents($path, json_encode(['calls' => $hook->calls, 'provider_ms' => $hook->nanoseconds / 1e6], JSON_THROW_ON_ERROR));
    }
});

(new Worker(new Extension(
    identifier: 'alfredbez/mago-phpunit',
    name: 'PHPUnit benchmark',
    version: '0.1.0',
    analyzerPlugins: [$plugin],
)))->run();
