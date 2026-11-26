<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier;
use Mago\Sdk\Analyzer\Type\FunctionLikeKind;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Span;
use PhpToken;

/** @internal */
final class LegacyMockSourceIndex
{
    /** @var array<string, self> */
    private array $cache = [];
    /** @var list<PhpToken> */
    private array $tokens = [];
    /** @var list<array{pos: int, text: string, doc: bool}> */
    private array $annotations = [];
    /** @var array<int, int> */
    private array $pairs = [];
    /** @var list<array{kind: string, start: int, end: int, body: int, parameters: ?int, name: ?string}> */
    private array $scopes = [];
    /** @var list<array{start: int, end: int, name: string}> */
    private array $namespaces = [];
    /** @var list<array{start: int, namespace: int, aliases: array<string, string>}> */
    private array $imports = [];
    /** @var list<array{start: int, namespace: int, aliases: array<string, string>}> */
    private array $functionImports = [];

    public function reset(): void
    {
        $this->cache = [];
    }

    /** @return null|array{class: ?string, function: ?FunctionLikeIdentifier, variable: ?string, property: ?string, type: ?Type, withoutMock: bool, plainCallCompatible: bool} */
    public function find(string $file, string $contents, Span $method, Span $receiverSpan, ?Codebase $codebase = null): ?array
    {
        $key = $file . ':' . hash('xxh128', $contents);
        $snapshot = $this->cache[$key] ?? null;
        if ($snapshot === null) {
            $snapshot = new self();
            $snapshot->index($contents);
            // IssueFilterHook processes one file per request, so keeping only the current snapshot bounds token memory.
            $this->cache = [$key => $snapshot];
        }
        return $snapshot->findInSnapshot($contents, $method, $receiverSpan, $codebase);
    }

    /** @return null|array{class: ?string, function: ?FunctionLikeIdentifier, variable: ?string, property: ?string, type: ?Type, withoutMock: bool, plainCallCompatible: bool} */
    private function findInSnapshot(string $contents, Span $method, Span $receiverSpan, ?Codebase $codebase): ?array
    {
        $at = $this->tokenAt($method->start);
        if ($at === null || strcasecmp($this->tokens[$at]->text, 'method') !== 0 && strcasecmp($this->tokens[$at]->text, 'expects') !== 0) {
            return null;
        }
        $methodLower = strtolower($this->tokens[$at]->text);
        $class = $this->enclosing($at, ['class']);
        $owner = $class === null ? null : $class['name'];
        $plainCallCompatible = $this->isPlainCallCompatible($at, $contents, $methodLower, $owner, $codebase);
        $constructed = $owner === null ? null : $this->constructedReceiver($at, $receiverSpan, $owner, $codebase);
        if ($constructed !== null) {
            return ['class' => $owner, 'function' => null, 'variable' => null, 'property' => null, 'type' => $constructed, 'withoutMock' => false, 'plainCallCompatible' => $plainCallCompatible];
        }
        $receiver = preg_replace('/\s+/', '', substr($contents, $receiverSpan->start, $receiverSpan->length()));
        if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*$/D', $receiver) !== 1
            && preg_match('/^\$this->([a-zA-Z_][a-zA-Z0-9_]*)$/D', $receiver, $property) !== 1) {
            return null;
        }
        $excluded = $this->excludedCondition($at, $receiver);
        $result = ['class' => $owner, 'function' => null, 'variable' => null, 'property' => null, 'type' => null, 'withoutMock' => $excluded !== null, 'plainCallCompatible' => $plainCallCompatible];
        if (isset($property[1])) {
            $scope = $this->enclosing($at, ['method']);
            $excluded = $scope === null ? null : $this->excludedCondition($at, $receiver, $scope['start']);
            $result['withoutMock'] = $excluded !== null;
            if ($scope !== null && !$this->hasWrite($scope['body'] + 1, $at, $receiver, $excluded)) {
                $result['property'] = $property[1];
            }
            return $result;
        }
        $before = $at;
        while (($scope = $this->enclosing($before, ['method', 'function', 'closure', 'arrow'])) !== null) {
            $parameters = $this->parameters($scope['parameters']);
            foreach ($parameters as [$name]) {
                if ($name === $receiver) {
                    $excluded = $this->excludedCondition($at, $receiver, $scope['start']);
                    $result['withoutMock'] = $excluded !== null;
                    break;
                }
            }
            if ($this->hasWrite($scope['body'] + 1, $before, $receiver, $excluded)) {
                return $result;
            }
            foreach ($parameters as [$name, $type]) {
                if ($name !== $receiver) {
                    continue;
                }
                $result['variable'] = substr($receiver, 1);
                $result['type'] = $type;
                if ($scope['kind'] === 'method' && $owner !== null) {
                    $result['function'] = new FunctionLikeIdentifier(FunctionLikeKind::Method, $scope['name'], $owner);
                } elseif ($scope['kind'] === 'function') {
                    $result['function'] = new FunctionLikeIdentifier(FunctionLikeKind::Function_, $scope['name']);
                }
                return $result;
            }
            if ($scope['kind'] === 'method' || $scope['kind'] === 'function') {
                return $result;
            }
            if ($scope['kind'] === 'closure' && !$this->captures($scope, $receiver)) {
                return $result;
            }
            $before = $scope['start'];
        }
        return $result;
    }

    private function index(string $contents): void
    {
        $tokens = PhpToken::tokenize($contents);
        $this->annotations = [];
        foreach ($tokens as $token) {
            if ($token->id === T_DOC_COMMENT || $token->id === T_COMMENT && str_contains($token->text, '@')) {
                $this->annotations[] = ['pos' => $token->pos, 'text' => $token->text, 'doc' => $token->id === T_DOC_COMMENT];
            }
        }
        $this->tokens = array_values(array_filter($tokens, static fn(PhpToken $token): bool => !$token->isIgnorable() && !in_array($token->id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)));
        $this->pairs = $this->scopes = $this->namespaces = $this->imports = $this->functionImports = [];
        /** @var list<int> $stack */
        $stack = [];
        foreach ($this->tokens as $index => $token) {
            if (in_array($token->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true) || in_array($token->text, ['(', '[', '{'], true)) {
                $stack[] = $index;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $open = array_pop($stack);
                if ($open === null) {
                    return;
                }
                $opening = $this->tokens[$open];
                $expected = match ($token->text) { ')' => '(', ']' => '[', '}' => '{' };
                if ($opening->text !== $expected
                    && !($expected === '{' && in_array($opening->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
                    && !($expected === '[' && $opening->id === T_ATTRIBUTE)) {
                    return;
                }
                $this->pairs[$open] = $index;
                $this->pairs[$index] = $open;
            }
        }
        if ($stack !== []) {
            return;
        }
        $count = count($this->tokens);
        $this->namespaces[] = ['start' => 0, 'end' => $count, 'name' => ''];
        foreach ($this->tokens as $index => $token) {
            if ($token->id !== T_NAMESPACE) {
                continue;
            }
            $end = $index + 1;
            while ($end < $count && !in_array($this->tokens[$end]->text, [';', '{'], true)) {
                $end++;
            }
            $name = $this->text($index + 1, $end);
            if ($name !== '' && !$this->isName($name)) {
                continue;
            }
            $last = array_key_last($this->namespaces);
            if ($this->namespaces[$last]['end'] === $count) {
                $this->namespaces[$last]['end'] = $index;
            }
            $this->namespaces[] = ['start' => $end + 1, 'end' => ($this->tokens[$end]->text ?? '') === '{' ? ($this->pairs[$end] ?? $count) : $count, 'name' => trim($name, '\\')];
        }
        foreach ($this->tokens as $index => $token) {
            $previous = $this->tokens[$index - 1] ?? null;
            if ($previous?->id === T_FUNCTION || ($previous?->text === '&' && ($this->tokens[$index - 2]->id ?? null) === T_FUNCTION)
                || in_array($previous?->text, ['->', '?->', '::'], true)) {
                continue;
            }
            if (in_array($token->id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                $body = $this->nextBody($index + 1);
                if ($body !== null) {
                    $name = ($this->tokens[$index + 1]->id ?? null) === T_STRING ? $this->tokens[$index + 1]->text : null;
                    $this->scopes[] = ['kind' => 'class', 'start' => $index, 'end' => $this->pairs[$body], 'body' => $body, 'parameters' => null, 'name' => $name === null ? null : $this->qualify($name, $index)];
                }
            }
            if ($token->id !== T_FUNCTION && $token->id !== T_FN) {
                continue;
            }
            $cursor = $index + 1;
            if (($this->tokens[$cursor]->text ?? '') === '&') {
                $cursor++;
            }
            $name = isset($this->tokens[$cursor]) && preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $this->tokens[$cursor]->text) === 1 ? $this->tokens[$cursor++]->text : null;
            if (($this->tokens[$cursor]->text ?? '') !== '(' || !isset($this->pairs[$cursor])) {
                continue;
            }
            $parameters = $cursor;
            $cursor = $this->pairs[$cursor] + 1;
            while ($cursor < $count && !in_array($this->tokens[$cursor]->text, ['{', ';', '=>'], true)) {
                $cursor = isset($this->pairs[$cursor]) && $this->pairs[$cursor] > $cursor ? $this->pairs[$cursor] + 1 : $cursor + 1;
            }
            if ($cursor >= $count || $this->tokens[$cursor]->text === ';') {
                continue;
            }
            $arrow = $token->id === T_FN;
            $owner = $this->enclosing($index, ['class']);
            $outer = $this->enclosing($index, ['method', 'function', 'closure', 'arrow']);
            $method = $name !== null && $owner !== null && ($outer === null || $outer['start'] < $owner['start']);
            $this->scopes[] = ['kind' => $arrow ? 'arrow' : ($name === null ? 'closure' : ($method ? 'method' : 'function')), 'start' => $index, 'end' => $arrow ? $this->expressionEnd($cursor + 1) : ($this->pairs[$cursor] ?? $cursor), 'body' => $cursor, 'parameters' => $parameters, 'name' => $name === null || $method ? $name : $this->qualify($name, $index)];
        }
        foreach ($this->tokens as $index => $token) {
            if ($token->id !== T_USE || $this->enclosing($index, ['class', 'method', 'function', 'closure', 'arrow']) !== null) {
                continue;
            }
            $end = $index + 1;
            while ($end < $count && $this->tokens[$end]->text !== ';') {
                $end++;
            }
            $ns = $this->namespaceAt($index);
            $classAliases = $this->importAliases($index + 1, $end, false);
            if ($classAliases !== []) {
                $this->imports[] = ['start' => $index, 'namespace' => $ns, 'aliases' => $classAliases];
            }
            $fnAliases = $this->importAliases($index + 1, $end, true);
            if ($fnAliases !== []) {
                $this->functionImports[] = ['start' => $index, 'namespace' => $ns, 'aliases' => $fnAliases];
            }
        }
    }

    private function constructedReceiver(int $at, Span $receiver, string $owner, ?Codebase $codebase): ?Type
    {
        $scope = $this->enclosing($at, ['method', 'function', 'closure', 'arrow']);
        $start = $this->tokenAt($receiver->start);
        if ($scope === null || $start === null) {
            return null;
        }
        $end = $start;
        while (isset($this->tokens[$end]) && $this->tokens[$end]->pos < $receiver->end) {
            $end++;
        }
        $direct = $this->resolvePlainExpression($start, $end, $scope, $owner, $codebase);
        if ($direct !== null) {
            return $this->annotationState($scope['body'], $at, null)['blocked'] ? null : $direct;
        }
        if ($end !== $start + 1 || $this->tokens[$start]->id !== T_VARIABLE || $this->tokens[$scope['body']]->text !== '{') {
            return null;
        }
        $variable = $this->tokens[$start]->text;
        $annotations = $this->annotationState($scope['body'], $at, $variable);
        if ($annotations['blocked']) {
            return null;
        }
        $blockOpen = $scope['body'];
        foreach ($this->pairs as $open => $close) {
            if ($open > $blockOpen && $open < $start && $start < $close && $this->tokens[$open]->text === '{') {
                $blockOpen = $open;
            }
        }
        $blockClose = $this->pairs[$blockOpen] ?? null;
        if ($blockClose === null) {
            return null;
        }
        if (($this->tokens[$blockOpen - 1]->text ?? '') === ')' && isset($this->pairs[$blockOpen - 1])
            && ($this->tokens[$this->pairs[$blockOpen - 1] - 1]->id ?? null) === T_SWITCH) {
            return null;
        }
        $aliasedEarlier = $this->hasReferenceAlias($scope['start'], $start, $variable);
        $latest = null;
        $cursor = $blockOpen + 1;
        while ($cursor < $blockClose) {
            $stmtEnd = $this->statementEnd($cursor);
            if ($stmtEnd === null || $stmtEnd <= $cursor) {
                return null;
            }
            if ($cursor <= $start && $start < $stmtEnd) {
                if ($this->isControlOrLabel($cursor) || $this->mentions($cursor, $start, $variable) || ($aliasedEarlier && $cursor < $start)) {
                    return null;
                }
                break;
            }
            if ($this->tokens[$cursor]->text === $variable && ($this->tokens[$cursor + 1]->text ?? '') === '='
                && ($this->tokens[$cursor + 2]->text ?? '') !== '&' && $this->tokens[$stmtEnd - 1]->text === ';') {
                $latest = [$cursor, $cursor + 2, $stmtEnd - 1];
            } elseif ($aliasedEarlier
                || ($this->isControlOrLabel($cursor) && (in_array($this->tokens[$cursor]->id, [T_GOTO, T_CASE, T_DEFAULT], true) || ($this->tokens[$cursor + 1]->text ?? '') === ':' || $this->mentions($cursor, $stmtEnd, $variable)))
                || $this->mentions($cursor, $stmtEnd, $variable)) {
                $latest = null;
            }
            $cursor = $stmtEnd;
        }
        if ($latest === null) {
            return null;
        }
        $rhs = $this->resolvePlainExpression($latest[1], $latest[2], $scope, $owner, $codebase);
        if ($rhs !== null) {
            if ($annotations['type'] !== null && strcasecmp($this->singleNamedObject($annotations['type']) ?? '', $this->singleNamedObject($rhs) ?? '') !== 0) {
                return null;
            }
            return $rhs;
        }
        $prevPos = $this->tokens[max($blockOpen, $latest[0] - 1)]->pos;
        $assignPos = $this->tokens[$latest[0]]->pos;
        return $annotations['type'] !== null && $annotations['pos'] !== null
            && $annotations['pos'] >= $prevPos && $annotations['pos'] < $assignPos
            ? $annotations['type']
            : null;
    }

    /** @param array{kind: string, start: int, end: int, body: int, parameters: ?int, name: ?string} $scope */
    private function resolvePlainExpression(int $start, int $end, array $scope, string $owner, ?Codebase $codebase): ?Type
    {
        while (($this->tokens[$start]->text ?? '') === '(' && ($this->pairs[$start] ?? null) === $end - 1) {
            $start++;
            $end--;
        }
        if ($start >= $end) {
            return null;
        }
        if (($type = $this->namedConstructor($start, $end)) !== null) {
            return $type;
        }
        if (($type = $this->dynamicConstructor($start, $end, $scope, $owner, $codebase)) !== null) {
            return $type;
        }
        if (($type = $this->conditionalExpression($start, $end, $scope, $owner, $codebase)) !== null) {
            return $type;
        }
        return $this->factoryCall($start, $end, $owner, $codebase);
    }

    /** @param array{kind: string, start: int, end: int, body: int, parameters: ?int, name: ?string} $scope */
    private function dynamicConstructor(int $start, int $end, array $scope, string $owner, ?Codebase $codebase): ?Type
    {
        if ($codebase === null || ($this->tokens[$start]->id ?? null) !== T_NEW || ($this->tokens[$start + 1]->id ?? null) !== T_VARIABLE) {
            return null;
        }
        $arguments = $start + 2;
        if ($arguments !== $end && (($this->tokens[$arguments]->text ?? '') !== '(' || ($this->pairs[$arguments] ?? null) !== $end - 1)) {
            return null;
        }
        $classVar = $this->tokens[$start + 1]->text;
        if ($this->hasWrite($scope['body'] + 1, $start, $classVar, null)
            || $this->annotationState($scope['body'], $start, null)['blocked']) {
            return null;
        }
        $function = match ($scope['kind']) {
            'method' => $scope['name'] === null ? null : new FunctionLikeIdentifier(FunctionLikeKind::Method, $scope['name'], $owner),
            'function' => $scope['name'] === null ? null : new FunctionLikeIdentifier(FunctionLikeKind::Function_, $scope['name']),
            default => null,
        };
        if ($function === null) {
            return null;
        }
        $paramType = null;
        foreach ($codebase->getFunctionLike($function)?->parameters ?? [] as $parameter) {
            if (ltrim($parameter->name, '$') === substr($classVar, 1)) {
                $paramType = $parameter->type?->type;
                break;
            }
        }
        if ($paramType === null || count($paramType->atomicTypes) !== 1 || !$paramType->atomicTypes[0] instanceof ScalarType
            || !$paramType->atomicTypes[0]->refinement instanceof ClassLikeStringType) {
            return null;
        }
        $refinement = $paramType->atomicTypes[0]->refinement;
        if ($refinement->variant === ClassLikeStringVariant::OfType && $refinement->constraint instanceof NamedObjectType
            && ($refinement->constraint->intersections === null || $refinement->constraint->intersections === [])) {
            return Type::namedObject($refinement->constraint->name);
        }
        if ($refinement->variant === ClassLikeStringVariant::Literal && $refinement->literal !== null) {
            return Type::namedObject($refinement->literal);
        }
        return null;
    }

    /** @param array{kind: string, start: int, end: int, body: int, parameters: ?int, name: ?string} $scope */
    private function conditionalExpression(int $start, int $end, array $scope, string $owner, ?Codebase $codebase): ?Type
    {
        $question = null;
        $colon = null;
        $depth = 0;
        for ($i = $start; $i < $end; $i++) {
            if (isset($this->pairs[$i]) && $this->pairs[$i] > $i) {
                $i = $this->pairs[$i];
                continue;
            }
            if ($this->tokens[$i]->text === '?') {
                if ($question === null) {
                    $question = $i;
                } else {
                    $depth++;
                }
            } elseif ($this->tokens[$i]->text === ':' && $question !== null) {
                if ($depth === 0) {
                    $colon = $i;
                    break;
                }
                $depth--;
            }
        }
        if ($question === null || $colon === null || $colon === $question + 1) {
            return null;
        }
        $left = $this->resolvePlainExpression($question + 1, $colon, $scope, $owner, $codebase);
        $right = $this->resolvePlainExpression($colon + 1, $end, $scope, $owner, $codebase);
        $leftName = $this->singleNamedObject($left);
        $rightName = $this->singleNamedObject($right);
        return $leftName !== null && $rightName !== null && strcasecmp($leftName, $rightName) === 0 ? $left : null;
    }

    private function factoryCall(int $start, int $end, string $owner, ?Codebase $codebase): ?Type
    {
        if ($codebase === null || $end - $start < 3 || ($this->tokens[$end - 1]->text ?? '') !== ')') {
            return null;
        }
        $open = $this->pairs[$end - 1] ?? null;
        if ($open === null || $open <= $start) {
            return null;
        }
        $returnType = null;
        if ($open === $start + 3 && $this->tokens[$start]->text === '$this' && $this->tokens[$start + 1]->text === '->'
            && $this->tokens[$start + 2]->id === T_STRING) {
            $returnType = $codebase->getMethod($owner, $this->tokens[$start + 2]->text)?->returnType?->type;
        } elseif ($open === $start + 3 && $this->tokens[$start + 1]->text === '::' && $this->tokens[$start + 2]->id === T_STRING) {
            $classText = $this->tokens[$start]->text;
            $lower = strtolower($classText);
            $targetClass = match (true) {
                $lower === 'self' => $owner,
                $lower === 'static' => ($codebase->getClassLike($owner)?->flags->contains(MetadataFlags::FINAL) ?? false) ? $owner : null,
                $lower === 'parent' => null,
                $this->isName($classText) => $this->resolve($classText, $start),
                default => null,
            };
            if ($targetClass !== null) {
                $returnType = $codebase->getMethod($targetClass, $this->tokens[$start + 2]->text)?->returnType?->type;
            }
        } elseif ($open === $start + 1 && in_array($this->tokens[$start]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
            && $this->isName($this->tokens[$start]->text)) {
            $name = $this->tokens[$start]->text;
            $resolved = $this->resolveFunction($name, $start);
            $fn = $codebase->getFunction($resolved);
            if ($fn === null && !str_contains($name, '\\')) {
                $fn = $codebase->getFunction($name);
            }
            $returnType = $fn?->returnType?->type;
        }
        $name = $this->singleNamedObject($returnType);
        return $name === null ? null : Type::namedObject($name);
    }

    /** @return array{blocked: bool, type: ?Type, pos: ?int} */
    private function annotationState(int $body, int $before, ?string $variable): array
    {
        $bodyPos = $this->tokens[$body]->pos;
        $beforePos = $this->tokens[$before]->pos;
        $resolved = null;
        $resolvedPos = null;
        foreach ($this->annotations as $annotation) {
            if ($annotation['pos'] < $bodyPos || $annotation['pos'] >= $beforePos) {
                continue;
            }
            if ($variable === null) {
                if (preg_match('/@(?:var|phpstan-|psalm-)/i', $annotation['text']) === 1) {
                    return ['blocked' => true, 'type' => null, 'pos' => null];
                }
                continue;
            }
            if (preg_match('/@(?:phpstan-|psalm-)/i', $annotation['text']) === 1) {
                return ['blocked' => true, 'type' => null, 'pos' => null];
            }
            if (preg_match_all('/@var\s+([^\s\*]+)(?:\s+(\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*))?/i', $annotation['text'], $matches, PREG_SET_ORDER)) {
                if (!$annotation['doc']) {
                    return ['blocked' => true, 'type' => null, 'pos' => null];
                }
                foreach ($matches as $match) {
                    $target = $match[2] ?? '';
                    if ($target !== '' && $target !== $variable) {
                        continue;
                    }
                    if ($target === '') {
                        return ['blocked' => true, 'type' => null, 'pos' => null];
                    }
                    $typeText = trim($match[1]);
                    if (!$this->isName($typeText) || in_array(strtolower($typeText), ['int', 'float', 'string', 'bool', 'mixed', 'object', 'array', 'iterable', 'callable', 'null', 'false', 'true', 'self', 'parent', 'static', 'void', 'never', 'resource'], true)) {
                        return ['blocked' => true, 'type' => null, 'pos' => null];
                    }
                    $candidate = $this->resolve($typeText, $before);
                    if ($resolved !== null && strcasecmp($resolved, $candidate) !== 0) {
                        return ['blocked' => true, 'type' => null, 'pos' => null];
                    }
                    $resolved = $candidate;
                    $resolvedPos = $annotation['pos'];
                }
            }
        }
        return ['blocked' => false, 'type' => $resolved === null ? null : Type::namedObject($resolved), 'pos' => $resolvedPos];
    }

    private function isControlOrLabel(int $at): bool
    {
        return in_array($this->tokens[$at]->id, [T_IF, T_ELSEIF, T_ELSE, T_FOR, T_FOREACH, T_WHILE, T_DO, T_SWITCH, T_CASE, T_DEFAULT, T_BREAK, T_CONTINUE, T_TRY, T_CATCH, T_FINALLY, T_DECLARE, T_GOTO], true)
            || ($this->tokens[$at]->id === T_STRING && ($this->tokens[$at + 1]->text ?? '') === ':');
    }

    private function hasReferenceAlias(int $start, int $before, string $variable): bool
    {
        for ($at = $start; $at < $before; $at++) {
            if ($this->tokens[$at]->text === '&' && ($this->tokens[$at + 1]->text ?? '') === $variable) {
                return true;
            }
            if ($this->tokens[$at]->text === $variable && ($this->tokens[$at + 1]->text ?? '') === '=' && ($this->tokens[$at + 2]->text ?? '') === '&') {
                return true;
            }
            if ($this->tokens[$at]->id === T_GLOBAL) {
                $end = $at + 1;
                while ($end < $before && $this->tokens[$end]->text !== ';') {
                    $end++;
                }
                if ($this->mentions($at + 1, $end, $variable)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function singleNamedObject(?Type $type): ?string
    {
        if ($type === null || count($type->atomicTypes) !== 1 || !$type->atomicTypes[0] instanceof NamedObjectType) {
            return null;
        }
        $atomic = $type->atomicTypes[0];
        return $atomic->intersections === null || $atomic->intersections === [] ? $atomic->name : null;
    }

    private function namedConstructor(int $start, int $end): ?Type
    {
        while (($this->tokens[$start]->text ?? '') === '(' && ($this->pairs[$start] ?? null) === $end - 1) {
            $start++;
            $end--;
        }
        if (($this->tokens[$start]->id ?? null) !== T_NEW) {
            return null;
        }
        $name = $this->tokens[$start + 1] ?? null;
        if ($name === null || !in_array($name->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
            || !$this->isName($name->text) || in_array(strtolower($name->text), ['self', 'parent', 'static'], true)) {
            return null;
        }
        $arguments = $start + 2;
        if ($arguments !== $end && (($this->tokens[$arguments]->text ?? '') !== '(' || ($this->pairs[$arguments] ?? null) !== $end - 1)) {
            return null;
        }
        return Type::namedObject($this->resolve($name->text, $start));
    }

    private function tokenAt(int $offset): ?int
    {
        $low = 0;
        $high = count($this->tokens) - 1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            $token = $this->tokens[$middle];
            if ($token->pos === $offset) {
                return $middle;
            }
            if ($token->pos < $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }
        return null;
    }

    /** @param list<string> $kinds
     * @return null|array{kind: string, start: int, end: int, body: int, parameters: ?int, name: ?string}
     */
    private function enclosing(int $at, array $kinds): ?array
    {
        $found = null;
        foreach ($this->scopes as $scope) {
            if ($scope['start'] < $at && $at < $scope['end'] && in_array($scope['kind'], $kinds, true)
                && ($found === null || $scope['start'] > $found['start'])) {
                $found = $scope;
            }
        }
        return $found;
    }

    private function nextBody(int $at): ?int
    {
        for ($count = count($this->tokens); $at < $count; $at++) {
            if ($this->tokens[$at]->text === '{') {
                return isset($this->pairs[$at]) ? $at : null;
            }
            if ($this->tokens[$at]->text === ';') {
                return null;
            }
            if (isset($this->pairs[$at]) && $this->pairs[$at] > $at) {
                $at = $this->pairs[$at];
            }
        }
        return null;
    }

    private function expressionEnd(int $at): int
    {
        $ternaries = 0;
        for ($count = count($this->tokens); $at < $count; $at++) {
            if ($this->tokens[$at]->text === '?') {
                $ternaries++;
            } elseif ($this->tokens[$at]->text === ':' && $ternaries-- === 0) {
                return $at;
            }
            if ($this->tokens[$at]->id === T_CLOSE_TAG || in_array($this->tokens[$at]->text, [',', ';', ')', ']', '}'], true)) {
                return $at;
            }
            if (isset($this->pairs[$at]) && $this->pairs[$at] > $at) {
                $at = $this->pairs[$at];
            }
        }
        return $at;
    }

    /** @return list<array{string, ?Type}> */
    private function parameters(?int $open): array
    {
        if ($open === null) {
            return [];
        }
        $parameters = [];
        $start = $open + 1;
        for ($at = $start; $at < $this->pairs[$open]; $at++) {
            $token = $this->tokens[$at];
            if ($token->id === T_VARIABLE) {
                while (($this->tokens[$start]->id ?? null) === T_ATTRIBUTE && isset($this->pairs[$start])) {
                    $start = $this->pairs[$start] + 1;
                }
                $hint = $this->text($start, $at);
                $hint = preg_replace('/(?:&|\.\.\.)+$/', '', $hint);
                $type = null;
                if ($this->isName($hint) || preg_match('/^[\\\\A-Za-z_\x80-\xff][\\\\A-Za-z0-9_\x80-\xff]*(?:\|[\\\\A-Za-z_\x80-\xff][\\\\A-Za-z0-9_\x80-\xff]*)+$/D', $hint) === 1) {
                    foreach (explode('|', $hint) as $member) {
                        $name = $this->resolve($member, $at);
                        if (in_array(strtolower($member), ['int', 'float', 'string', 'bool', 'mixed', 'object', 'array', 'iterable', 'callable', 'null', 'false', 'true', 'self', 'parent', 'static'], true)) {
                            $type = null;
                            break;
                        }
                        $part = Type::namedObject($name);
                        $type = $type === null ? $part : Type::union($type, $part);
                    }
                }
                $parameters[] = [$token->text, $type];
                $at++;
                while ($at < $this->pairs[$open] && $this->tokens[$at]->text !== ',') {
                    if (isset($this->pairs[$at]) && $this->pairs[$at] > $at) {
                        $at = $this->pairs[$at];
                    }
                    $at++;
                }
                $start = $at + 1;
            } elseif (isset($this->pairs[$at]) && $this->pairs[$at] > $at) {
                $at = $this->pairs[$at];
            }
        }
        return $parameters;
    }

    /** @param array{parameters: ?int} $scope */
    private function captures(array $scope, string $receiver): bool
    {
        $at = $this->pairs[$scope['parameters']] + 1;
        if (($this->tokens[$at]->id ?? null) !== T_USE || ($this->tokens[$at + 1]->text ?? '') !== '(') {
            return false;
        }
        for ($end = $this->pairs[$at + 1], $at += 2; $at < $end; $at++) {
            if ($this->tokens[$at]->text === $receiver) {
                return ($this->tokens[$at - 1]->text ?? '') !== '&';
            }
        }
        return false;
    }

    private function namespaceAt(int $at): int
    {
        foreach ($this->namespaces as $index => $namespace) {
            if ($namespace['start'] <= $at && $at < $namespace['end']) {
                return $index;
            }
        }
        return 0;
    }

    private function qualify(string $name, int $at): string
    {
        $namespace = $this->namespaces[$this->namespaceAt($at)]['name'];
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    private function resolve(string $name, int $at): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return $this->qualify(substr($name, 10), $at);
        }
        $parts = explode('\\', $name, 2);
        $namespace = $this->namespaceAt($at);
        $resolved = null;
        foreach ($this->imports as $import) {
            if ($import['start'] < $at && $import['namespace'] === $namespace) {
                $resolved = $import['aliases'][strtolower($parts[0])] ?? $resolved;
            }
        }
        return $resolved === null ? $this->qualify($name, $at) : $resolved . (isset($parts[1]) ? '\\' . $parts[1] : '');
    }

    private function resolveFunction(string $name, int $at): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return $this->qualify(substr($name, 10), $at);
        }
        if (str_contains($name, '\\')) {
            return $this->resolve($name, $at);
        }
        $namespace = $this->namespaceAt($at);
        $resolved = null;
        foreach ($this->functionImports as $import) {
            if ($import['start'] < $at && $import['namespace'] === $namespace) {
                $resolved = $import['aliases'][strtolower($name)] ?? $resolved;
            }
        }
        return $resolved ?? $this->qualify($name, $at);
    }

    /** @return array<string, string> */
    private function importAliases(int $start, int $end, bool $functions = false): array
    {
        if (!isset($this->tokens[$start])) {
            return [];
        }
        $leading = $this->tokens[$start]->id;
        if ($leading === T_CONST) {
            return [];
        }
        $leadingFunction = $leading === T_FUNCTION;
        if ($leadingFunction) {
            if (!$functions) {
                return [];
            }
            $start++;
        }
        $text = $this->text($start, $end, ' ');
        $prefix = '';
        if (($brace = strpos($text, '{')) !== false) {
            $prefix = preg_replace('/\s+/', '', substr($text, 0, $brace));
            $text = substr($text, $brace + 1, strrpos($text, '}') - $brace - 1);
        } elseif ($functions && !$leadingFunction) {
            return [];
        }
        $aliases = [];
        foreach (explode(',', $text) as $entry) {
            $entryIsFunction = $leadingFunction;
            if (preg_match('/^\s*const\b/i', $entry) === 1) {
                continue;
            }
            if (preg_match('/^\s*function\s+(.+)$/i', $entry, $fnMatch) === 1) {
                $entryIsFunction = true;
                $entry = $fnMatch[1];
            }
            if ($entryIsFunction !== $functions) {
                continue;
            }
            $parts = preg_split('/\s+as\s+/i', trim($entry));
            $name = trim($prefix . preg_replace('/\s+/', '', $parts[0]), '\\');
            $alias = $parts[1] ?? substr($name, (int) strrpos('\\' . $name, '\\'));
            if ($this->isName($name)) {
                $aliases[strtolower(trim($alias))] = $name;
            }
        }
        return $aliases;
    }

    private function isName(string $name): bool
    {
        return preg_match('/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D', $name) === 1;
    }

    private function text(int $start, int $end, string $separator = ''): string
    {
        return implode($separator, array_map(static fn(PhpToken $token): string => $token->text, array_slice($this->tokens, $start, $end - $start)));
    }

    /** @return null|array{int, int} */
    private function excludedCondition(int $before, string $receiver, int $binding = 0): ?array
    {
        foreach ($this->tokens as $at => $token) {
            if ($at >= $before) {
                break;
            }
            if ($at < $binding || $token->id !== T_IF || ($this->tokens[$at + 1]->text ?? '') !== '(') {
                continue;
            }
            $open = $at + 1;
            $close = $this->pairs[$open] ?? $open;
            $body = $close + 1;
            $bodyEnd = $this->statementEnd($body);
            if ($bodyEnd === null) {
                continue;
            }
            $start = $open + 1;
            $end = $close;
            $negative = ($this->tokens[$start]->text ?? '') === '!';
            if ($negative) {
                $start++;
            }
            while (($this->tokens[$start]->text ?? '') === '(' && ($this->pairs[$start] ?? null) === $end - 1) {
                $start++;
                $end--;
            }
            $instance = null;
            for ($cursor = $start; $cursor < $end; $cursor++) {
                if ($this->tokens[$cursor]->id === T_INSTANCEOF) {
                    $instance = $cursor;
                    break;
                }
            }
            if ($instance === null || $this->text($start, $instance) !== $receiver
                || strcasecmp($this->resolve($this->text($instance + 1, $end), $instance), 'PHPUnit\\Framework\\MockObject\\MockObject') !== 0) {
                continue;
            }
            $inside = $body <= $before && $before < $bodyEnd;
            $elseBody = $bodyEnd + 1;
            $elseEnd = ($this->tokens[$bodyEnd]->id ?? null) === T_ELSE ? $this->statementEnd($elseBody) : null;
            $insideElse = $elseEnd !== null && $elseBody <= $before && $before < $elseEnd;
            if ($negative && $inside || !$negative && $insideElse) {
                return [$open, $close];
            }
        }
        return null;
    }

    private function statementEnd(int $at): ?int
    {
        $token = $this->tokens[$at] ?? null;
        if ($token === null) {
            return null;
        }
        if ($token->text === '{') {
            return $this->pairs[$at] + 1;
        }
        if (in_array($token->id, [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH, T_DECLARE], true)) {
            $open = $at + 1;
            if (($this->tokens[$open]->text ?? '') !== '(') {
                return null;
            }
            $end = $this->statementEnd($this->pairs[$open] + 1);
            if ($end !== null && in_array($token->id, [T_IF, T_ELSEIF], true)) {
                if (($this->tokens[$end]->id ?? null) === T_ELSE) {
                    return $this->statementEnd($end + 1);
                }
                if (($this->tokens[$end]->id ?? null) === T_ELSEIF) {
                    return $this->statementEnd($end);
                }
            }
            return $end;
        }
        if ($token->id === T_DO) {
            $end = $this->statementEnd($at + 1);
            $open = $end === null ? null : $end + 1;
            return ($this->tokens[$end]->id ?? null) === T_WHILE && ($this->tokens[$open]->text ?? '') === '('
                && ($this->tokens[$this->pairs[$open] + 1]->text ?? '') === ';' ? $this->pairs[$open] + 2 : null;
        }
        if ($token->id === T_TRY) {
            $end = $this->statementEnd($at + 1);
            while ($end !== null && ($this->tokens[$end]->id ?? null) === T_CATCH) {
                $end = ($this->tokens[$end + 1]->text ?? '') === '(' ? $this->statementEnd($this->pairs[$end + 1] + 1) : null;
            }
            return $end !== null && ($this->tokens[$end]->id ?? null) === T_FINALLY ? $this->statementEnd($end + 1) : $end;
        }
        if ($token->text === ':') {
            return null;
        }
        for ($count = count($this->tokens); $at < $count; $at++) {
            if ($this->tokens[$at]->text === ';' || $this->tokens[$at]->id === T_CLOSE_TAG) {
                return $at + 1;
            }
            if (isset($this->pairs[$at])) {
                if ($this->pairs[$at] < $at) {
                    return null;
                }
                $at = $this->pairs[$at];
            }
        }
        return null;
    }

    /** @param null|array{int, int} $excluded */
    private function hasWrite(int $start, int $before, string $receiver, ?array $excluded): bool
    {
        $parameters = [];
        $independent = [];
        foreach ($this->scopes as $scope) {
            if ($scope['parameters'] !== null) {
                $parameters[$scope['parameters']] = $this->pairs[$scope['parameters']];
                if (!str_starts_with($receiver, '$this->') && $start <= $scope['start'] && $scope['end'] < $before
                    && $this->hasIndependentBinding($scope, $receiver)) {
                    $independent[$scope['start']] = $scope['end'];
                }
            } elseif ($scope['kind'] === 'class' && str_starts_with($receiver, '$this->')
                && $start <= $scope['body'] && $scope['end'] < $before) {
                $independent[$scope['body']] = $scope['end'];
            }
        }
        for ($at = $start; $at < $before; $at++) {
            if (isset($independent[$at])) {
                $at = $independent[$at] - 1;
                continue;
            }
            if (isset($parameters[$at])) {
                $at = $parameters[$at];
                continue;
            }
            $token = $this->tokens[$at];
            if (in_array($token->text, ['=', '+=', '-=', '*=', '/=', '.=', '%=', '&=', '|=', '^=', '<<=', '>>=', '**=', '??=', '++', '--'], true)) {
                $left = $at - 1;
                while ($left >= $start) {
                    if (isset($this->pairs[$left]) && $this->pairs[$left] < $left) {
                        $left = $this->pairs[$left] - 1;
                        continue;
                    }
                    if (!$this->tokens[$left]->is(T_VARIABLE) && !$this->tokens[$left]->is(T_STRING)
                        && !in_array($this->tokens[$left]->text, ['->', '?->', '$', '::'], true)) {
                        break;
                    }
                    $left--;
                }
                $end = $at;
                if (in_array($token->text, ['++', '--'], true) && $left === $at - 1) {
                    $end = $at + 1;
                    while ($end < $before && ($this->tokens[$end]->id === T_VARIABLE || $this->tokens[$end]->id === T_STRING
                        || in_array($this->tokens[$end]->text, ['->', '?->', '$', '::', '['], true))) {
                        $end = isset($this->pairs[$end]) && $this->pairs[$end] > $end ? $this->pairs[$end] + 1 : $end + 1;
                    }
                } elseif (($this->tokens[$at + 1]->text ?? '') === '&') {
                    $end = min($before, $this->expressionEnd($at + 1));
                }
                if ($this->mentions($left + 1, $end, $receiver)) {
                    return true;
                }
            }
            if ($token->id === T_INSTANCEOF && ($excluded === null || $at < $excluded[0] || $at > $excluded[1])) {
                $left = $at - 1;
                while ($left >= $start && !in_array($this->tokens[$left]->text, ['(', ';', '{', '}', '&&', '||', ','], true)) {
                    $left--;
                }
                if ($this->mentions($left + 1, $at, $receiver)) {
                    return true;
                }
            }
            if (in_array($token->id, [T_AS, T_CATCH, T_UNSET, T_GLOBAL], true)) {
                $end = $at + 1;
                if (isset($this->pairs[$end]) && $this->pairs[$end] > $end) {
                    $end = $this->pairs[$end];
                } else {
                    while ($end < $before && !in_array($this->tokens[$end]->text, [')', ';'], true)) {
                        $end++;
                    }
                }
                if ($this->mentions($at + 1, $end, $receiver)) {
                    return true;
                }
            }
            if ($token->id === T_USE && ($this->tokens[$at + 1]->text ?? '') === '(') {
                for ($cursor = $at + 2, $end = $this->pairs[$at + 1]; $cursor < $end; $cursor++) {
                    if ($this->tokens[$cursor]->text === '&' && $this->mentions($cursor + 1, $cursor + 2, $receiver)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** @param array{parameters: int, end: int, body: int} $scope */
    private function hasIndependentBinding(array $scope, string $receiver): bool
    {
        for ($at = $this->pairs[$scope['parameters']] + 1; $at < $scope['body']; $at++) {
            if ($this->tokens[$at]->text === '&' && ($this->tokens[$at + 1]->text ?? '') === $receiver) {
                return false;
            }
        }
        for ($at = $scope['body'] + 1; $at < $scope['end']; $at++) {
            if ($this->tokens[$at]->id !== T_GLOBAL) {
                continue;
            }
            $end = $at + 1;
            while ($end < $scope['end'] && $this->tokens[$end]->text !== ';') {
                $end++;
            }
            if ($this->mentions($at + 1, $end, $receiver)) {
                return false;
            }
            $at = $end;
        }
        return true;
    }

    private function mentions(int $start, int $end, string $receiver): bool
    {
        if (str_starts_with($receiver, '$this->')) {
            for ($at = $start; $at + 2 < $end; $at++) {
                if ($this->tokens[$at]->id === T_VARIABLE && $this->tokens[$at]->text === '$this'
                    && $this->tokens[$at + 1]->text === '->' && $this->tokens[$at + 2]->text === substr($receiver, 7)) {
                    return true;
                }
            }
            return false;
        }
        for ($at = $start; $at < $end; $at++) {
            if ($this->tokens[$at]->id === T_VARIABLE && $this->tokens[$at]->text === $receiver
                || $this->tokens[$at]->text === '$' || $this->tokens[$at]->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                return true;
            }
        }
        return false;
    }

    private function isPlainCallCompatible(int $at, string $contents, string $methodLower, ?string $owner, ?Codebase $codebase): bool
    {
        $open = $at + 1;
        if (($this->tokens[$open]->text ?? '') !== '(' || !isset($this->pairs[$open])) {
            return false;
        }
        $close = $this->pairs[$open];
        $openPos = $this->tokens[$open]->pos;
        $closePos = $this->tokens[$close]->pos;
        $rawArgs = substr($contents, $openPos + 1, $closePos - $openPos - 1);

        $rawTokens = array_values(array_filter(
            PhpToken::tokenize('<?php ' . $rawArgs),
            static fn (PhpToken $t): bool => $t->id !== T_OPEN_TAG && !$t->isIgnorable(),
        ));
        if ($rawTokens === []) {
            return false;
        }

        // Split top-level arguments on commas at bracket depth 0.
        $args = [];
        $current = [];
        $depth = 0;
        foreach ($rawTokens as $token) {
            if ($depth === 0 && $token->text === ',') {
                $args[] = $current;
                $current = [];
                continue;
            }
            if (in_array($token->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true)
                || in_array($token->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            }
            $current[] = $token;
        }
        if ($current !== []) {
            $args[] = $current;
        }
        if (count($args) !== 1 || $args[0] === []) {
            return false;
        }

        $arg = $args[0];
        // Strip named-argument label (`name: expr`).
        if (count($arg) >= 3 && ($arg[1]->text ?? '') === ':' && preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $arg[0]->text) === 1) {
            $arg = array_slice($arg, 2);
        }
        if ($arg === [] || $arg[0]->id === T_ELLIPSIS) {
            return false;
        }
        // Unwrap enclosing parentheses.
        while (count($arg) >= 2 && $arg[0]->text === '(' && $arg[count($arg) - 1]->text === ')') {
            $d = 0;
            $wrapsAll = true;
            for ($i = 0, $n = count($arg); $i < $n - 1; $i++) {
                if ($arg[$i]->text === '(') {
                    $d++;
                } elseif ($arg[$i]->text === ')') {
                    $d--;
                }
                if ($d === 0) {
                    $wrapsAll = false;
                    break;
                }
            }
            if (!$wrapsAll) {
                break;
            }
            $arg = array_slice($arg, 1, -1);
        }
        if ($arg === []) {
            return false;
        }

        $first = $arg[0];
        // String literals (single-quoted, double-quoted, heredoc/nowdoc).
        if ($first->id === T_CONSTANT_ENCAPSED_STRING || $first->id === T_START_HEREDOC || $first->text === '"') {
            return $methodLower === 'method';
        }
        // Array literals.
        if ($first->text === '[' || $first->id === T_ARRAY) {
            return false;
        }
        // Numeric literals (optionally signed).
        if (in_array($first->id, [T_LNUMBER, T_DNUMBER], true)
            || (in_array($first->text, ['+', '-'], true) && isset($arg[1]) && in_array($arg[1]->id, [T_LNUMBER, T_DNUMBER], true) && count($arg) === 2)) {
            return false;
        }
        // Null and boolean literals.
        if (count($arg) === 1 && $first->id === T_STRING && in_array(strtolower($first->text), ['null', 'true', 'false'], true)) {
            return false;
        }
        // `new ClassName(...)`.
        if ($first->id === T_NEW && isset($arg[1]) && $this->isName($arg[1]->text) && !in_array(strtolower($arg[1]->text), ['self', 'parent', 'static'], true)) {
            if ($codebase === null) {
                return true;
            }
            $resolved = $this->resolve($arg[1]->text, $at);
            return LegacyMockCalls::isTypeCompatibleWithPlainCall($codebase, $methodLower, Type::namedObject($resolved));
        }
        // Single variable `$var`: resolve parameter type in enclosing scope if available.
        if (count($arg) === 1 && $first->id === T_VARIABLE && $codebase !== null) {
            $scope = $this->enclosing($at, ['method', 'function']);
            if ($scope !== null && $scope['name'] !== null) {
                $fnId = $scope['kind'] === 'method' && $owner !== null
                    ? new FunctionLikeIdentifier(FunctionLikeKind::Method, $scope['name'], $owner)
                    : ($scope['kind'] === 'function' ? new FunctionLikeIdentifier(FunctionLikeKind::Function_, $scope['name']) : null);
                if ($fnId !== null) {
                    foreach ($codebase->getFunctionLike($fnId)?->parameters ?? [] as $parameter) {
                        if (ltrim($parameter->name, '$') === substr($first->text, 1)) {
                            return LegacyMockCalls::isTypeCompatibleWithPlainCall($codebase, $methodLower, $parameter->type?->type);
                        }
                    }
                }
            }
            return true;
        }
        // `$this->prop` property access.
        if (count($arg) === 3 && $first->text === '$this' && $arg[1]->text === '->' && $arg[2]->id === T_STRING && $owner !== null && $codebase !== null) {
            $propType = $codebase->getProperty($owner, '$' . $arg[2]->text)?->type?->type;
            return LegacyMockCalls::isTypeCompatibleWithPlainCall($codebase, $methodLower, $propType);
        }

        return true;
    }
}
