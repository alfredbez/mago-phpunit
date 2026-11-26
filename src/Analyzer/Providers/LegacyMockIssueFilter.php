<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\AnnotationKind;

/** @internal */
final class LegacyMockIssueFilter implements IssueFilterHook
{
    public function __construct(private readonly LegacyMockSourceIndex $sourceIndex) {}

    public function getCodes(): array
    {
        return ['non-existent-method', 'possibly-invalid-argument'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code === 'possibly-invalid-argument'
            && preg_match('/expected `([^`]+)`, but possibly received `([^`]+)`/', $context->issue->message, $match) === 1) {
            $members = explode('|', $match[2]);
            $hasMock = false;
            $hasTarget = false;
            foreach ($members as $member) {
                if (strcasecmp($member, 'PHPUnit\\Framework\\MockObject\\MockObject') === 0) {
                    $hasMock = true;
                } elseif (strcasecmp($member, $match[1]) === 0) {
                    $hasTarget = true;
                } else {
                    return IssueFilterDecision::Keep;
                }
            }
            return $hasMock && $hasTarget ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
        }
        if ($context->issue->code !== 'non-existent-method'
            || preg_match('/^Method `(method|expects)` does not exist on type `([^`]+)`\./', $context->issue->message, $match) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $primary = null;
        $receiver = null;
        foreach ($context->issue->annotations as $annotation) {
            if ($annotation->kind === AnnotationKind::Primary) {
                $primary = $annotation->span;
            } elseif ($receiver === null) {
                $receiver = $annotation->span;
            }
        }
        if ($primary === null || $receiver === null) {
            return IssueFilterDecision::Keep;
        }
        $declaration = $this->sourceIndex->find($context->file, $context->contents, $primary, $receiver, $context->codebase);
        if ($declaration === null) {
            return IssueFilterDecision::Keep;
        }
        $type = $declaration['type'];
        if ($declaration['function'] !== null) {
            foreach ($context->codebase->getFunctionLike($declaration['function'])?->parameters ?? [] as $parameter) {
                if (ltrim($parameter->name, '$') === $declaration['variable']) {
                    $type = $parameter->type?->type;
                    break;
                }
            }
        } elseif ($declaration['property'] !== null && $declaration['class'] !== null) {
            $type = $context->codebase->getProperty($declaration['class'], '$' . $declaration['property'])?->type?->type;
        }
        if ($declaration['withoutMock'] && LegacyMockCalls::isLegacyUnion($type)) {
            $members = array_values(array_filter(
                $type->atomicTypes,
                static fn ($member): bool => !$member instanceof NamedObjectType
                    || strcasecmp($member->name, 'PHPUnit\\Framework\\MockObject\\MockObject') !== 0,
            ));
            $type = Type::fromAtomics(...$members);
        }
        if (LegacyMockCalls::isLegacyUnion($type)) {
            foreach ($type->atomicTypes as $member) {
                if ($member instanceof NamedObjectType && strcasecmp($member->name, $match[2]) === 0) {
                    return IssueFilterDecision::Remove;
                }
            }
        }
        if ($declaration['plainCallCompatible']
            && $type !== null && count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof NamedObjectType
            && strcasecmp($type->atomicTypes[0]->name, $match[2]) === 0
            && LegacyMockCalls::isTestClass($context->codebase, $declaration['class'])
            && LegacyMockCalls::isPlainMockTarget($context->codebase, $match[2], $match[1])) {
            return IssueFilterDecision::Remove;
        }
        return IssueFilterDecision::Keep;
    }
}
