<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\MethodFields;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\AnnotationKind;

final class LegacyMockIssueFilter implements IssueFilterHook
{
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
        $expression = substr($context->contents, $receiver->start, $receiver->length());
        $methods = $context->codebase->findMethods(
            descendantsOf: 'PHPUnit\\Framework\\TestCase',
            fields: MethodFields::LOCATIONS | MethodFields::PARAMETERS,
            declaredOnly: true,
        );
        foreach ($methods as $method) {
            if ($method->location?->file !== $context->file || !$method->location->span->contains($primary)) {
                continue;
            }
            $type = null;
            if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/D', $expression, $variable) === 1) {
                foreach ($method->parameters ?? [] as $parameter) {
                    if (ltrim($parameter->name, '$') === $variable[1]) {
                        $type = $parameter->type?->type;
                    }
                }
                $beforeCall = substr($context->contents, $method->location->span->start, $receiver->start - $method->location->span->start);
                if (self::hasShadowingFunction($beforeCall, $variable[1])) {
                    return IssueFilterDecision::Keep;
                }
                if (preg_match('/\$' . preg_quote($variable[1], '/') . '\s*(?:=(?!=)|\?\?=|\+=|-=|\+\+|--)/', $beforeCall) === 1) {
                    return IssueFilterDecision::Keep;
                }
            } elseif (preg_match('/^\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)$/D', $expression, $property) === 1) {
                $type = $context->codebase->getProperty($method->method->class, $property[1])?->type?->type;
            }
            if (LegacyMockCalls::isLegacyUnion($type)) {
                return IssueFilterDecision::Remove;
            }
            if ($type !== null && count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof NamedObjectType
                && strcasecmp($type->atomicTypes[0]->name, $match[2]) === 0
                && !$context->codebase->methodExists($match[2], '__call')) {
                return IssueFilterDecision::Remove;
            }
        }
        return IssueFilterDecision::Keep;
    }

    private static function hasShadowingFunction(string $prefix, string $variable): bool
    {
        $scopes = [];
        $pending = null;
        $depth = 0;
        $parsingParameters = false;
        $outerFunction = true;
        $arrowShadow = false;
        foreach (token_get_all('<?php ' . $prefix) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_FUNCTION || $token[0] === T_FN) {
                    $pending = ['shadow' => false, 'arrow' => $token[0] === T_FN, 'outer' => $outerFunction];
                    $outerFunction = false;
                    $parsingParameters = true;
                    $depth = 0;
                } elseif ($token[0] === T_VARIABLE && $parsingParameters && $pending !== null && $token[1] === '$' . $variable) {
                    $pending['shadow'] = true;
                } elseif ($token[0] === T_DOUBLE_ARROW && $pending !== null && $pending['arrow']) {
                    $arrowShadow = $pending['shadow'] && !$pending['outer'];
                    $pending = null;
                }
                continue;
            }
            if ($parsingParameters) {
                if ($token === '(') {
                    $depth++;
                } elseif ($token === ')' && --$depth === 0) {
                    $parsingParameters = false;
                }
            }
            if ($token === ';') {
                $arrowShadow = false;
            }
            if ($token === '{') {
                $scopes[] = $pending;
                $pending = null;
            } elseif ($token === '}') {
                array_pop($scopes);
            }
        }
        if ($arrowShadow) {
            return true;
        }
        foreach ($scopes as $scope) {
            if ($scope !== null && !$scope['outer'] && $scope['shadow']) {
                return true;
            }
        }
        return false;
    }
}
