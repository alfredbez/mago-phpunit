<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier;
use Mago\Sdk\Analyzer\Type\FunctionLikeKind;
use Mago\Sdk\Span;
use PhpToken;

final class LegacyMockSourceIndex
{
    private string $key = '';
    /** @var list<PhpToken> */
    private array $tokens = [];
    /** @var array<int, int> */
    private array $pairs = [];
    /** @var list<array{kind: string, start: int, end: int, body: int, parameters: ?int, name: ?string}> */
    private array $scopes = [];
    /** @var list<array{start: int, end: int, name: string}> */
    private array $namespaces = [];
    /** @var list<array{start: int, namespace: int, aliases: array<string, string>}> */
    private array $imports = [];

    /** @return null|array{class: ?string, function: ?FunctionLikeIdentifier, variable: ?string, property: ?string, type: ?Type, withoutMock: bool} */
    public function find(string $file, string $contents, Span $method, Span $receiverSpan): ?array
    {
        $key = $file . ':' . hash('xxh128', $contents);
        if ($this->key !== $key) {
            $this->index($contents);
            $this->key = $key;
        }
        $at = $this->tokenAt($method->start);
        if ($at === null || strcasecmp($this->tokens[$at]->text, 'method') !== 0 && strcasecmp($this->tokens[$at]->text, 'expects') !== 0) {
            return null;
        }
        $receiver = preg_replace('/\s+/', '', substr($contents, $receiverSpan->start, $receiverSpan->length()));
        if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*$/D', $receiver) !== 1
            && preg_match('/^\$this->([a-zA-Z_][a-zA-Z0-9_]*)$/D', $receiver, $property) !== 1) {
            return null;
        }
        $class = $this->enclosing($at, ['class']);
        $owner = $class === null ? null : $class['name'];
        $excluded = $this->excludedCondition($at, $receiver);
        $result = ['class' => $owner, 'function' => null, 'variable' => null, 'property' => null, 'type' => null, 'withoutMock' => $excluded !== null];
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
        $this->tokens = array_values(array_filter(PhpToken::tokenize($contents), static fn(PhpToken $token): bool => !$token->isIgnorable() && !in_array($token->id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)));
        $this->pairs = $this->scopes = $this->namespaces = $this->imports = [];
        $stack = [];
        foreach ($this->tokens as $index => $token) {
            if (in_array($token->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true) || in_array($token->text, ['(', '[', '{'], true)) {
                $stack[] = $index;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $open = array_pop($stack);
                $opening = $open === null ? null : $this->tokens[$open];
                $expected = match ($token->text) { ')' => '(', ']' => '[', '}' => '{' };
                if ($opening === null || ($opening->text !== $expected
                    && !($expected === '{' && in_array($opening->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
                    && !($expected === '[' && $opening->id === T_ATTRIBUTE))) {
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
            $this->namespaces[] = ['start' => $end + 1, 'end' => $this->tokens[$end]->text === '{' ? ($this->pairs[$end] ?? $count) : $count, 'name' => trim($name, '\\')];
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
            $this->imports[] = ['start' => $index, 'namespace' => $this->namespaceAt($index), 'aliases' => $this->importAliases($index + 1, $end)];
        }
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

    /** @return array<string, string> */
    private function importAliases(int $start, int $end): array
    {
        if (in_array($this->tokens[$start]->id, [T_FUNCTION, T_CONST], true)) {
            return [];
        }
        $text = $this->text($start, $end, ' ');
        $prefix = '';
        if (($brace = strpos($text, '{')) !== false) {
            $prefix = preg_replace('/\s+/', '', substr($text, 0, $brace));
            $text = substr($text, $brace + 1, strrpos($text, '}') - $brace - 1);
        }
        $aliases = [];
        foreach (explode(',', $text) as $entry) {
            if (preg_match('/^\s*(?:function|const)\b/i', $entry) === 1) {
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
        foreach ($this->scopes as $scope) {
            if ($scope['parameters'] !== null) {
                $parameters[$scope['parameters']] = $this->pairs[$scope['parameters']];
            }
        }
        for ($at = $start; $at < $before; $at++) {
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
}
