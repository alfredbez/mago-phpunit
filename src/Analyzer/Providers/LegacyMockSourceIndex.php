<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier;
use Mago\Sdk\Analyzer\Type\FunctionLikeKind;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

final class LegacyMockSourceIndex implements CodebaseScanHook
{
    /** @var array<string, array<int, array{class: ?string, function: ?FunctionLikeIdentifier, variable: ?string, property: ?string, type: ?Type, withoutMock: bool}>> */
    private array $calls = [];

    public function getTargets(): array
    {
        return ['*'];
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->calls = [];
        }
        foreach ($context->files as $source) {
            foreach ($source->getNodes() as $node) {
                if ($node->kind !== NodeKind::MethodCall && $node->kind !== NodeKind::NullSafeMethodCall) {
                    continue;
                }
                $call = CallExpression::fromNode($source, $node);
                if (!in_array(strtolower($call->getName($source) ?? ''), ['method', 'expects'], true)) {
                    continue;
                }
                $this->calls[$source->path][$call->member->span->start] = $this->declaration($source, $call);
            }
        }
    }

    /** @return null|array{class: ?string, function: ?FunctionLikeIdentifier, variable: ?string, property: ?string, type: ?Type, withoutMock: bool} */
    public function find(string $file, Span $method): ?array
    {
        return $this->calls[$file][$method->start] ?? null;
    }

    /** @return array{class: ?string, function: ?FunctionLikeIdentifier, variable: ?string, property: ?string, type: ?Type, withoutMock: bool} */
    private function declaration(SourceFile $source, CallExpression $call): array
    {
        $owner = null;
        $ancestors = $source->getAncestors($call->node);
        foreach ($ancestors as $ancestor) {
            if (in_array($ancestor->kind, [NodeKind::Class_, NodeKind::Trait, NodeKind::AnonymousClass], true)) {
                $owner = $this->name($source, $ancestor, true);
                break;
            }
        }
        $result = ['class' => $owner, 'function' => null, 'variable' => null, 'property' => null, 'type' => null, 'withoutMock' => false];
        $receiver = $source->getText($call->receiver);
        $excludedMock = $this->excludedMockCondition($source, $call, $receiver);
        $result['withoutMock'] = $excludedMock !== null;
        if (preg_match('/^\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)$/D', $receiver, $match) === 1) {
            foreach ($ancestors as $scope) {
                if ($scope->kind === NodeKind::Method) {
                    if (!$this->hasWrite($source, $scope, $receiver, $call->node->span->start, $excludedMock)) {
                        $result['property'] = $match[1];
                    }
                    break;
                }
            }
            return $result;
        }
        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/D', $receiver) !== 1) {
            return $result;
        }
        $before = $call->node->span->start;
        foreach ($ancestors as $scope) {
            if (!in_array($scope->kind, [NodeKind::Method, NodeKind::Function, NodeKind::Closure, NodeKind::ArrowFunction], true)) {
                continue;
            }
            if ($this->hasWrite($source, $scope, $receiver, $before, $excludedMock)) {
                return $result;
            }
            foreach ($source->getChildren($scope) as $child) {
                if ($child->kind !== NodeKind::FunctionLikeParameterList) {
                    continue;
                }
                foreach ($source->getChildren($child) as $parameter) {
                    foreach ($source->getChildren($parameter) as $part) {
                        if ($part->kind !== NodeKind::DirectVariable || $source->getText($part) !== $receiver) {
                            continue;
                        }
                        $result['variable'] = ltrim($receiver, '$');
                        $result['type'] = $this->parameterType($source, $parameter);
                        if ($scope->kind === NodeKind::Method && $owner !== null) {
                            $result['function'] = new FunctionLikeIdentifier(FunctionLikeKind::Method, $this->name($source, $scope, false), $owner);
                        } elseif ($scope->kind === NodeKind::Function) {
                            $result['function'] = new FunctionLikeIdentifier(FunctionLikeKind::Function_, $this->name($source, $scope, true));
                        }
                        return $result;
                    }
                }
            }
            if ($scope->kind === NodeKind::Method || $scope->kind === NodeKind::Function) {
                return $result;
            }
            if ($scope->kind === NodeKind::Closure) {
                $captured = false;
                foreach ($source->getChildren($scope) as $child) {
                    if ($child->kind !== NodeKind::ClosureUseClause) {
                        continue;
                    }
                    foreach ($source->getDescendants($child, NodeKind::ClosureUseClauseVariable) as $capture) {
                        if ($source->getText($capture) === $receiver) {
                            $captured = true;
                        }
                    }
                }
                if (!$captured) {
                    return $result;
                }
            }
            $before = $scope->span->start;
        }
        return $result;
    }

    private function name(SourceFile $source, Node $declaration, bool $resolved): ?string
    {
        foreach ($source->getChildren($declaration) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                return $resolved ? $source->getResolvedName($child)?->name : $source->getText($child);
            }
        }
        return null;
    }

    private function parameterType(SourceFile $source, Node $parameter): ?Type
    {
        foreach ($source->getChildren($parameter) as $hint) {
            if ($hint->kind !== NodeKind::Hint) {
                continue;
            }
            $names = $source->getResolvedNames($hint);
            if ($names === [] || count($names) !== count(explode('|', $source->getText($hint))) || preg_match('/^[\\\\A-Za-z_][\\\\A-Za-z0-9_]*(?:\s*\|\s*[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)*$/D', $source->getText($hint)) !== 1) {
                return null;
            }
            $type = null;
            foreach ($names as $name) {
                $member = Type::namedObject($name->name);
                $type = $type === null ? $member : Type::union($type, $member);
            }
            return $type;
        }
        return null;
    }

    private function excludedMockCondition(SourceFile $source, CallExpression $call, string $receiver): ?Node
    {
        foreach ($source->getAncestors($call->node) as $ancestor) {
            if ($ancestor->kind !== NodeKind::If) {
                continue;
            }
            $condition = null;
            $body = null;
            foreach ($source->getChildren($ancestor) as $child) {
                if ($child->kind === NodeKind::Expression) {
                    $condition = $child;
                } elseif ($child->kind === NodeKind::IfBody) {
                    $body = $source->getChildren($child)[0] ?? null;
                }
            }
            if ($condition === null || $body?->kind !== NodeKind::IfStatementBody
                || preg_match('/^(!)?\s*(?:\(\s*)?' . preg_quote($receiver, '/') . '\s+instanceof\s+[\\\\A-Za-z_][\\\\A-Za-z0-9_]*\s*(?:\))?$/D', $source->getText($condition), $match) !== 1) {
                continue;
            }
            $names = $source->getResolvedNames($condition);
            if (count($names) !== 1 || strcasecmp($names[0]->name, 'PHPUnit\\Framework\\MockObject\\MockObject') !== 0) {
                continue;
            }
            $negative = ($match[1] ?? '') === '!';
            foreach ($source->getChildren($body) as $branch) {
                $inBranch = $branch->span->start <= $call->node->span->start && $call->node->span->end <= $branch->span->end;
                if ($inBranch && (($negative && $branch->kind === NodeKind::Statement)
                    || (!$negative && $branch->kind === NodeKind::IfStatementBodyElseClause))) {
                    return $condition;
                }
            }
        }
        return null;
    }

    private function hasWrite(SourceFile $source, Node $scope, string $receiver, int $before, ?Node $excludedMock): bool
    {
        foreach ($source->getDescendants($scope) as $node) {
            if ($node->span->start >= $before) {
                break;
            }
            if ($excludedMock !== null && $node->kind === NodeKind::Binary
                && $excludedMock->span->start <= $node->span->start && $node->span->end <= $excludedMock->span->end) {
                continue;
            }
            $target = match ($node->kind) {
                NodeKind::Assignment => str_contains($source->getText($node), '=&') || preg_match('/=\s*&/', $source->getText($node)) === 1 ? $node : ($source->getChildren($node)[0] ?? null),
                NodeKind::UnaryPrefix, NodeKind::UnaryPostfix => str_contains($source->getText($node), '++') || str_contains($source->getText($node), '--') ? $node : null,
                NodeKind::Binary => str_contains($source->getText($node), 'instanceof') ? $node : null,
                NodeKind::ForeachTarget, NodeKind::TryCatchClause, NodeKind::Unset, NodeKind::Global => $node,
                NodeKind::ClosureUseClauseVariable => str_contains($source->getText($node), '&') ? $node : null,
                default => null,
            };
            if ($target === null) {
                continue;
            }
            if (preg_match('/^\$this\s*->/', $receiver) === 1) {
                foreach ([$target, ...$source->getDescendants($target, NodeKind::PropertyAccess)] as $property) {
                    if (preg_replace('/\s+/', '', $source->getText($property)) === preg_replace('/\s+/', '', $receiver)) {
                        return true;
                    }
                }
                continue;
            }
            if ($source->getDescendants($target, NodeKind::IndirectVariable) !== []
                || $source->getDescendants($target, NodeKind::NestedVariable) !== []) {
                return true;
            }
            foreach ([$target, ...$source->getDescendants($target, NodeKind::DirectVariable)] as $variable) {
                if ($variable->kind === NodeKind::DirectVariable && $source->getText($variable) === $receiver) {
                    return true;
                }
            }
        }
        return false;
    }
}
