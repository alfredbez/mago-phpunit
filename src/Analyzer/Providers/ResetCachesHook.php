<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use AlfredBez\MagoPhpunit\Linter\PhpUnitFile;
use Closure;
use Mago\Sdk\Analyzer\BeforeAnalysisContext;
use Mago\Sdk\Analyzer\BeforeAnalysisHook;

/**
 * Clears run-scoped per-file caches before each analysis pass so long-lived workers never leak stale state.
 *
 * @internal
 */
final class ResetCachesHook implements BeforeAnalysisHook
{
    /** @param list<Closure(): void> $resetters */
    public function __construct(private readonly array $resetters) {}

    public function beforeAnalysis(BeforeAnalysisContext $context): void
    {
        foreach ($this->resetters as $resetter) {
            $resetter();
        }
        PhpUnitFile::reset();
    }
}
