<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Syntax\NodeKind;

/** @internal */
final class AssertionQualityFunctionHook implements NodeAnalysisHook
{
    public function __construct(
        private readonly AssertionQualityHook $hook = new AssertionQualityHook(functions: true),
    ) {}

    public function reset(): void
    {
        $this->hook->reset();
    }

    public function getTargets(): array
    {
        return [NodeKind::FunctionCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $this->hook->analyze($context);
    }
}
