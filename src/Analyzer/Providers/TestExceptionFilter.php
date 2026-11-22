<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;

final class TestExceptionFilter implements IssueFilterHook
{
    public function getCodes(): array
    {
        return ['unhandled-thrown-type'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if (preg_match('/^Potentially unhandled exception `([^`]+)` in `([^`]+)`\./', $context->issue->message, $match) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $exception = strtolower(ltrim($match[1], '\\'));
        if (!str_starts_with($exception, 'phpunit\\') && !str_starts_with($exception, 'sebastianbergmann\\') && !str_starts_with($exception, 'prophecy\\')) {
            return IssueFilterDecision::Keep;
        }
        $separator = strpos($match[2], '::');
        if ($separator === false) {
            return IssueFilterDecision::Keep;
        }
        $owner = $context->codebase->getClassLike(substr($match[2], 0, $separator));
        foreach ($owner?->parentClasses ?? [] as $parent) {
            if (strcasecmp($parent, 'PHPUnit\\Framework\\TestCase') === 0) {
                return IssueFilterDecision::Remove;
            }
        }

        return IssueFilterDecision::Keep;
    }
}
