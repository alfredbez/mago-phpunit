<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Linter;

use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use Mago\Sdk\Syntax\TriviaKind;

/** @internal */
final class PhpUnitFile
{
    private static ?self $lastFile = null;

    /** @var list<AssertionCall> */
    public array $calls = [];

    /** @var array<int, array{node: Node, name: string, parent: string, methods: array<string, Node>}> */
    public array $classes = [];

    /** @var array<string, int> */
    private array $classNames = [];

    private function __construct(
        public readonly SourceFile $file,
    ) {
        foreach ($file->getNodes(NodeKind::Class_) as $class) {
            $identifier = $this->child($class, NodeKind::LocalIdentifier);
            if ($identifier === null) {
                continue;
            }
            $name = $file->getResolvedName($identifier)?->name ?? $file->getText($identifier);
            $extends = $this->child($class, NodeKind::Extends);
            $parent = $extends === null ? null : $file->getFirstDescendant($extends, NodeKind::Identifier);
            $parentName = $parent === null ? '' : $file->getResolvedName($parent)?->name ?? '';
            $methods = [];
            foreach ($file->getChildren($class) as $member) {
                if ($member->kind !== NodeKind::ClassLikeMember) {
                    continue;
                }
                $method = $this->child($member, NodeKind::Method);
                $methodName = $method === null ? null : $this->child($method, NodeKind::LocalIdentifier);
                if ($method !== null && $methodName !== null) {
                    $methods[strtolower($file->getText($methodName))] = $method;
                }
            }
            $this->classes[$class->id] = [
                'node' => $class,
                'name' => $name,
                'parent' => $parentName,
                'methods' => $methods,
            ];
            $this->classNames[strtolower($name)] = $class->id;
        }

        foreach ($file->getNodes() as $node) {
            if (in_array(
                $node->kind,
                [NodeKind::MethodCall, NodeKind::StaticMethodCall, NodeKind::FunctionCall],
                true,
            )) {
                $call = $this->assertionCall($node);
                if ($call !== null) {
                    $this->calls[] = $call;
                }
            }
        }
    }

    public static function reset(): void
    {
        self::$lastFile = null;
    }

    public static function for(SourceFile $file): self
    {
        if (self::$lastFile?->file !== $file) {
            self::$lastFile = new self($file);
        }
        return self::$lastFile;
    }

    public function child(Node $node, NodeKind $kind): ?Node
    {
        foreach ($this->file->getChildren($node) as $child) {
            if ($child->kind === $kind) {
                return $child;
            }
        }
        return null;
    }

    public function classOf(Node $node): ?int
    {
        foreach ($this->file->getAncestors($node) as $ancestor) {
            if ($ancestor->kind === NodeKind::AnonymousClass || $ancestor->kind === NodeKind::Trait) {
                return null;
            }
            if ($ancestor->kind === NodeKind::Class_) {
                return $ancestor->id;
            }
        }
        return null;
    }

    public function isTestClass(int $id): bool
    {
        $seen = [];
        while (isset($this->classes[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            $parent = strtolower($this->classes[$id]['parent']);
            if ($parent === 'phpunit\\framework\\testcase') {
                return true;
            }
            $id = $this->classNames[$parent] ?? -1;
        }
        return false;
    }

    public function providerMethod(int $class, string $name): ?Node
    {
        $seen = [];
        while (isset($this->classes[$class]) && !isset($seen[$class])) {
            $seen[$class] = true;
            $method = $this->classes[$class]['methods'][strtolower($name)] ?? null;
            if ($method !== null) {
                return $method;
            }
            $class = $this->classNames[strtolower($this->classes[$class]['parent'])] ?? -1;
        }
        return null;
    }

    public function hasParentMethod(int $class, string $name): bool
    {
        $lower = strtolower($name);
        $seen = [$class => true];
        $parent = $this->classNames[strtolower($this->classes[$class]['parent'] ?? '')] ?? -1;
        while (isset($this->classes[$parent]) && !isset($seen[$parent])) {
            $seen[$parent] = true;
            if (isset($this->classes[$parent]['methods'][$lower])) {
                return true;
            }
            $parent = $this->classNames[strtolower($this->classes[$parent]['parent'])] ?? -1;
        }
        return false;
    }

    /** @return list<Node> */
    public function arguments(Node $list): array
    {
        $arguments = [];
        foreach ($this->file->getChildren($list) as $argument) {
            if (in_array($argument->kind, [NodeKind::Argument, NodeKind::PartialArgument], true)) {
                $arguments[] = $argument;
            }
        }
        return $arguments;
    }

    public function argumentValue(Node $argument): ?Node
    {
        $actual = $this->file->getChildren($argument)[0] ?? null;
        return $actual === null ? null : $this->child($actual, NodeKind::Expression);
    }

    public function literal(Node $node): ?string
    {
        $text = strtolower(trim($this->file->getText($node)));
        if (in_array($text, ['true', 'false', 'null'], true)) {
            return $text;
        }
        $tokens = array_values(array_filter(
            token_get_all('<?php ' . $text),
            static fn($t): bool => !is_array($t) || !in_array($t[0], [T_OPEN_TAG, T_WHITESPACE], true),
        ));
        if (count($tokens) === 2 && in_array($tokens[0], ['+', '-'], true)) {
            array_shift($tokens);
        }
        return count($tokens) === 1
        && is_array($tokens[0])
        && in_array($tokens[0][0], [T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING], true)
            ? 'scalar'
            : null;
    }

    public function stringLiteral(Node $node): ?string
    {
        $literal = $node->kind === NodeKind::LiteralString
            ? $node
            : $this->file->getFirstDescendant($node, NodeKind::LiteralString);
        if ($literal === null || trim($this->file->getText($node)) !== $this->file->getText($literal)) {
            return null;
        }
        $value = $this->file->getLiteralString($literal);
        if ($value !== null) {
            return $value;
        }
        $raw = $this->file->getText($literal);
        return (
            $raw[0] === "'"
                ? str_replace(["\\\\", "\\'"], ["\\", "'"], substr($raw, 1, -1))
                : stripcslashes(substr($raw, 1, -1))
        );
    }

    /** @return list<array{name: string, span: Span}> */
    public function docProviders(Node $method): array
    {
        $providers = [];
        foreach ($this->file->getTrivia() as $trivia) {
            if ($trivia->kind !== TriviaKind::DocBlockComment || $trivia->span->end > $method->span->start) {
                continue;
            }
            $between = substr($this->file->contents, $trivia->span->end, $method->span->start - $trivia->span->end);
            if (trim($between) !== '') {
                continue;
            }
            preg_match_all(
                '/@dataProvider\s+([a-zA-Z_][a-zA-Z0-9_]*)\b(?!::)/',
                $this->file->getText($trivia->span),
                $matches,
                PREG_OFFSET_CAPTURE,
            );
            foreach ($matches[1] as [$name, $offset]) {
                $start = $trivia->span->start + $offset;
                $providers[] = ['name' => $name, 'span' => new Span($start, $start + strlen($name))];
            }
        }
        return $providers;
    }

    private function assertionCall(Node $node): ?AssertionCall
    {
        $children = $this->file->getChildren($node);
        $receiver = $children[0] ?? null;
        $list = $this->child($node, NodeKind::ArgumentList);
        if ($receiver === null || $list === null) {
            return null;
        }
        $class = $this->classOf($node);
        $style = null;
        $receiverSpan = null;
        if ($node->kind === NodeKind::FunctionCall) {
            $resolved = $this->file->getResolvedName($receiver)?->name;
            if ($resolved === null || !str_starts_with(strtolower($resolved), 'phpunit\\framework\\')) {
                return null;
            }
            $name = substr($resolved, strlen('PHPUnit\\Framework\\'));
            $nameSpan = $receiver->span;
        } else {
            $selector = $this->child($node, NodeKind::ClassLikeMemberSelector);
            if ($selector === null || $this->child($selector, NodeKind::LocalIdentifier) === null) {
                return null;
            }
            $name = $this->file->getText($selector);
            $nameSpan = $selector->span;
            $receiverText = strtolower($this->file->getText($receiver));
            if (in_array($receiverText, ['$this', 'self', 'static'], true)) {
                if ($class === null || !$this->isTestClass($class)) {
                    return null;
                }
                if ($node->kind === NodeKind::MethodCall && $receiverText !== '$this') {
                    return null;
                }
                $style = $receiverText === '$this' ? 'this' : $receiverText;
                $receiverSpan = new Span($receiver->span->start, $selector->span->start);
            } else {
                $resolved = strtolower($this->file->getResolvedName($receiver)?->name ?? '');
                if (
                    $node->kind !== NodeKind::StaticMethodCall
                    || !in_array($resolved, ['phpunit\\framework\\assert', 'phpunit\\framework\\testcase'], true)
                ) {
                    return null;
                }
            }
        }
        if (!isset(self::ASSERTIONS[strtolower($name)])) {
            return null;
        }
        $arguments = $this->arguments($list);
        $positional = true;
        foreach ($arguments as $argument) {
            if (
                ($this->file->getChildren($argument)[0] ?? null)?->kind === NodeKind::NamedArgument
                || str_starts_with(ltrim($this->file->getText($argument)), '...')
            ) {
                $positional = false;
                break;
            }
        }
        $first = isset($arguments[0])
        && ($this->file->getChildren($arguments[0])[0] ?? null)?->kind !== NodeKind::NamedArgument
        && !str_starts_with(ltrim($this->file->getText($arguments[0])), '...')
            ? $this->argumentValue($arguments[0])
            : null;
        if (!$positional) {
            foreach ($arguments as $argument) {
                $actual = $this->file->getChildren($argument)[0] ?? null;
                $named = $actual?->kind === NodeKind::NamedArgument ? $actual : null;
                $label = $named === null ? null : $this->child($named, NodeKind::LocalIdentifier);
                if ($label !== null && strtolower($this->file->getText($label)) === 'expected') {
                    $first = $this->argumentValue($argument);
                    break;
                }
            }
        }
        return new AssertionCall(
            $node,
            strtolower($name),
            $nameSpan,
            $receiverSpan,
            $style,
            $arguments,
            $positional,
            $first === null ? null : $this->literal($first),
        );
    }

    private const ASSERTIONS = [
        'assertarrayisequaltoarrayonlyconsideringlistofkeys' => true,
        'assertarrayisequaltoarrayignoringlistofkeys' => true,
        'assertarrayisidenticaltoarrayonlyconsideringlistofkeys' => true,
        'assertarrayisidenticaltoarrayignoringlistofkeys' => true,
        'assertarrayhaskey' => true,
        'assertarraynothaskey' => true,
        'assertislist' => true,
        'assertcontains' => true,
        'assertcontainsequals' => true,
        'assertnotcontains' => true,
        'assertnotcontainsequals' => true,
        'assertcontainsonly' => true,
        'assertcontainsonlyarray' => true,
        'assertcontainsonlybool' => true,
        'assertcontainsonlycallable' => true,
        'assertcontainsonlyfloat' => true,
        'assertcontainsonlyint' => true,
        'assertcontainsonlyiterable' => true,
        'assertcontainsonlynull' => true,
        'assertcontainsonlynumeric' => true,
        'assertcontainsonlyobject' => true,
        'assertcontainsonlyresource' => true,
        'assertcontainsonlyclosedresource' => true,
        'assertcontainsonlyscalar' => true,
        'assertcontainsonlystring' => true,
        'assertcontainsonlyinstancesof' => true,
        'assertnotcontainsonly' => true,
        'assertcontainsnotonlyarray' => true,
        'assertcontainsnotonlybool' => true,
        'assertcontainsnotonlycallable' => true,
        'assertcontainsnotonlyfloat' => true,
        'assertcontainsnotonlyint' => true,
        'assertcontainsnotonlyiterable' => true,
        'assertcontainsnotonlynull' => true,
        'assertcontainsnotonlynumeric' => true,
        'assertcontainsnotonlyobject' => true,
        'assertcontainsnotonlyresource' => true,
        'assertcontainsnotonlyclosedresource' => true,
        'assertcontainsnotonlyscalar' => true,
        'assertcontainsnotonlystring' => true,
        'assertcontainsnotonlyinstancesof' => true,
        'assertcount' => true,
        'assertnotcount' => true,
        'assertequals' => true,
        'assertequalscanonicalizing' => true,
        'assertequalsignoringcase' => true,
        'assertequalswithdelta' => true,
        'assertnotequals' => true,
        'assertnotequalscanonicalizing' => true,
        'assertnotequalsignoringcase' => true,
        'assertnotequalswithdelta' => true,
        'assertobjectequals' => true,
        'assertobjectnotequals' => true,
        'assertempty' => true,
        'assertnotempty' => true,
        'assertgreaterthan' => true,
        'assertgreaterthanorequal' => true,
        'assertlessthan' => true,
        'assertlessthanorequal' => true,
        'assertfileequals' => true,
        'assertfileequalscanonicalizing' => true,
        'assertfileequalsignoringcase' => true,
        'assertfilenotequals' => true,
        'assertfilenotequalscanonicalizing' => true,
        'assertfilenotequalsignoringcase' => true,
        'assertstringequalsfile' => true,
        'assertstringequalsfilecanonicalizing' => true,
        'assertstringequalsfileignoringcase' => true,
        'assertstringnotequalsfile' => true,
        'assertstringnotequalsfilecanonicalizing' => true,
        'assertstringnotequalsfileignoringcase' => true,
        'assertisreadable' => true,
        'assertisnotreadable' => true,
        'assertiswritable' => true,
        'assertisnotwritable' => true,
        'assertdirectoryexists' => true,
        'assertdirectorydoesnotexist' => true,
        'assertdirectoryisreadable' => true,
        'assertdirectoryisnotreadable' => true,
        'assertdirectoryiswritable' => true,
        'assertdirectoryisnotwritable' => true,
        'assertfileexists' => true,
        'assertfiledoesnotexist' => true,
        'assertfileisreadable' => true,
        'assertfileisnotreadable' => true,
        'assertfileiswritable' => true,
        'assertfileisnotwritable' => true,
        'asserttrue' => true,
        'assertnottrue' => true,
        'assertfalse' => true,
        'assertnotfalse' => true,
        'assertnull' => true,
        'assertnotnull' => true,
        'assertfinite' => true,
        'assertinfinite' => true,
        'assertnan' => true,
        'assertobjecthasproperty' => true,
        'assertobjectnothasproperty' => true,
        'assertsame' => true,
        'assertnotsame' => true,
        'assertinstanceof' => true,
        'assertnotinstanceof' => true,
        'assertisarray' => true,
        'assertisbool' => true,
        'assertisfloat' => true,
        'assertisint' => true,
        'assertisnumeric' => true,
        'assertisobject' => true,
        'assertisresource' => true,
        'assertisclosedresource' => true,
        'assertisstring' => true,
        'assertisscalar' => true,
        'assertiscallable' => true,
        'assertisiterable' => true,
        'assertisnotarray' => true,
        'assertisnotbool' => true,
        'assertisnotfloat' => true,
        'assertisnotint' => true,
        'assertisnotnumeric' => true,
        'assertisnotobject' => true,
        'assertisnotresource' => true,
        'assertisnotclosedresource' => true,
        'assertisnotstring' => true,
        'assertisnotscalar' => true,
        'assertisnotcallable' => true,
        'assertisnotiterable' => true,
        'assertmatchesregularexpression' => true,
        'assertdoesnotmatchregularexpression' => true,
        'assertsamesize' => true,
        'assertnotsamesize' => true,
        'assertstringcontainsstringignoringlineendings' => true,
        'assertstringequalsstringignoringlineendings' => true,
        'assertfilematchesformat' => true,
        'assertfilematchesformatfile' => true,
        'assertstringmatchesformat' => true,
        'assertstringmatchesformatfile' => true,
        'assertstringstartswith' => true,
        'assertstringstartsnotwith' => true,
        'assertstringcontainsstring' => true,
        'assertstringcontainsstringignoringcase' => true,
        'assertstringnotcontainsstring' => true,
        'assertstringnotcontainsstringignoringcase' => true,
        'assertstringendswith' => true,
        'assertstringendsnotwith' => true,
        'assertxmlfileequalsxmlfile' => true,
        'assertxmlfilenotequalsxmlfile' => true,
        'assertxmlstringequalsxmlfile' => true,
        'assertxmlstringnotequalsxmlfile' => true,
        'assertxmlstringequalsxmlstring' => true,
        'assertxmlstringnotequalsxmlstring' => true,
        'assertthat' => true,
        'assertjson' => true,
        'assertjsonstringequalsjsonstring' => true,
        'assertjsonstringnotequalsjsonstring' => true,
        'assertjsonstringequalsjsonfile' => true,
        'assertjsonstringnotequalsjsonfile' => true,
        'assertjsonfileequalsjsonfile' => true,
        'assertjsonfilenotequalsjsonfile' => true,
    ];
}
