<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Linter;

use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;

/** @internal */
final class AssertionCall
{
    /** @param list<Node> $arguments */
    public function __construct(
        public readonly Node $node,
        public readonly string $name,
        public readonly Span $nameSpan,
        public readonly ?Span $receiverSpan,
        public readonly ?string $style,
        public readonly array $arguments,
        public readonly bool $positional,
        public readonly ?string $firstLiteral,
    ) {}
}
