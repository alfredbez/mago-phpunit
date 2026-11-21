<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\ClassLikeAnalysisHook;
use Mago\Sdk\Analyzer\ClassLikeTarget;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/**
 * Validates that classes, traits, methods, and functions referenced by PHPUnit coverage
 * attributes (#[CoversClass], #[CoversTrait], #[CoversMethod], #[CoversFunction],
 * #[UsesClass], #[UsesTrait], #[UsesMethod], #[UsesFunction]) exist in the codebase.
 */
final class CoverageTargetsHook implements ClassLikeAnalysisHook
{
    public const ISSUE_CODE = 'phpunit-invalid-covers-target';

    private const CLASS_TARGETS = [
        'phpunit\\framework\\attributes\\coversclass' => 'CoversClass',
        'phpunit\\framework\\attributes\\usesclass' => 'UsesClass',
    ];

    private const TRAIT_TARGETS = [
        'phpunit\\framework\\attributes\\coverstrait' => 'CoversTrait',
        'phpunit\\framework\\attributes\\usestrait' => 'UsesTrait',
    ];

    private const METHOD_TARGETS = [
        'phpunit\\framework\\attributes\\coversmethod' => 'CoversMethod',
        'phpunit\\framework\\attributes\\usesmethod' => 'UsesMethod',
    ];

    private const FUNCTION_TARGETS = [
        'phpunit\\framework\\attributes\\coversfunction' => 'CoversFunction',
        'phpunit\\framework\\attributes\\usesfunction' => 'UsesFunction',
    ];

    public function getTargets(): array
    {
        return [
            ClassLikeTarget::descendantsOf('PHPUnit\\Framework\\TestCase'),
        ];
    }

    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::SourceText,
            FileAnalysisRequirement::TargetSubtree,
        ];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $contents = $context->source->contents;
        if (stripos($contents, 'Covers') === false && stripos($contents, 'Uses') === false) {
            return;
        }

        $source = $context->source;
        $classNode = $context->node;
        $namespace = $this->enclosingNamespace($source, $classNode);

        $this->checkAttributeContainer($context, $source, $classNode, $namespace);
        foreach ($source->getChildren($classNode) as $member) {
            $method = $member->kind === NodeKind::Method
                ? $member
                : ($member->kind === NodeKind::ClassLikeMember ? ($source->getChildren($member)[0] ?? null) : null);
            if ($method !== null && $method->kind === NodeKind::Method) {
                $this->checkAttributeContainer($context, $source, $method, $namespace);
            }
        }
    }

    private function checkAttributeContainer(
        NodeAnalysisContext $context,
        SourceFile $source,
        Node $container,
        string $namespace,
    ): void {
        foreach ($source->getChildren($container) as $attributeList) {
            if ($attributeList->kind !== NodeKind::AttributeList) {
                continue;
            }

            foreach ($source->getChildren($attributeList) as $attribute) {
                if ($attribute->kind !== NodeKind::Attribute) {
                    continue;
                }

                $attrName = $this->attributeName($source, $attribute);
                if ($attrName === null) {
                    continue;
                }

                $lower = strtolower($attrName);
                if (isset(self::CLASS_TARGETS[$lower])) {
                    $this->checkClassTarget($context, $source, $attribute, self::CLASS_TARGETS[$lower]);
                } elseif (isset(self::TRAIT_TARGETS[$lower])) {
                    $this->checkTraitTarget($context, $source, $attribute, self::TRAIT_TARGETS[$lower]);
                } elseif (isset(self::METHOD_TARGETS[$lower])) {
                    $this->checkMethodTarget($context, $source, $attribute, self::METHOD_TARGETS[$lower]);
                } elseif (isset(self::FUNCTION_TARGETS[$lower])) {
                    $this->checkFunctionTarget($context, $source, $attribute, self::FUNCTION_TARGETS[$lower], $namespace);
                }
            }
        }
    }

    private function checkClassTarget(
        NodeAnalysisContext $context,
        SourceFile $source,
        Node $attribute,
        string $label,
    ): void {
        $args = $this->attributeArguments($source, $attribute);
        $classArg = $this->attributeArgument($source, $args, 0, 'className');
        if ($classArg === null) {
            return;
        }

        $targetClass = $this->extractClassReference($source, $classArg);
        if ($targetClass === null) {
            return;
        }

        if (!$context->codebase->classOrInterfaceExists($targetClass) && !$context->codebase->enumExists($targetClass)) {
            $this->report(
                $context,
                $classArg->span,
                "#[{$label}] target class `{$targetClass}` does not exist.",
            );
        }
    }

    private function checkTraitTarget(
        NodeAnalysisContext $context,
        SourceFile $source,
        Node $attribute,
        string $label,
    ): void {
        $args = $this->attributeArguments($source, $attribute);
        $traitArg = $this->attributeArgument($source, $args, 0, 'traitName');
        if ($traitArg === null) {
            return;
        }

        $targetTrait = $this->extractClassReference($source, $traitArg);
        if ($targetTrait === null) {
            return;
        }

        if (!$context->codebase->traitExists($targetTrait)) {
            $this->report(
                $context,
                $traitArg->span,
                "#[{$label}] target trait `{$targetTrait}` does not exist.",
            );
        }
    }

    private function checkMethodTarget(
        NodeAnalysisContext $context,
        SourceFile $source,
        Node $attribute,
        string $label,
    ): void {
        $args = $this->attributeArguments($source, $attribute);
        $classArg = $this->attributeArgument($source, $args, 0, 'className');
        $methodArg = $this->attributeArgument($source, $args, 1, 'methodName');
        if ($classArg === null || $methodArg === null) {
            return;
        }

        $targetClass = $this->extractClassReference($source, $classArg);
        $targetMethod = $this->literalString($source, $methodArg);
        if ($targetClass === null || $targetMethod === null || $targetMethod === '') {
            return;
        }

        if (!$context->codebase->classLikeExists($targetClass)) {
            $this->report(
                $context,
                $classArg->span,
                "#[{$label}] target class `{$targetClass}` does not exist.",
            );

            return;
        }

        $meta = $context->codebase->getClassLike($targetClass);
        if ($meta !== null && $meta->hasIncompleteHierarchy()) {
            return;
        }

        if (!$context->codebase->methodExists($targetClass, $targetMethod)) {
            $this->report(
                $context,
                $methodArg->span,
                "#[{$label}] target method `{$targetClass}::{$targetMethod}()` does not exist.",
            );
        }
    }

    private function checkFunctionTarget(
        NodeAnalysisContext $context,
        SourceFile $source,
        Node $attribute,
        string $label,
        string $namespace,
    ): void {
        $args = $this->attributeArguments($source, $attribute);
        $funcArg = $this->attributeArgument($source, $args, 0, 'functionName');
        if ($funcArg === null) {
            return;
        }

        $funcName = $this->literalString($source, $funcArg);
        if ($funcName === null || $funcName === '') {
            return;
        }

        $normalized = ltrim($funcName, '\\');
        if ($normalized === '') {
            return;
        }

        $candidates = [$normalized];
        if (!str_starts_with($funcName, '\\') && !str_contains($normalized, '\\') && $namespace !== '') {
            $candidates[] = $namespace . '\\' . $normalized;
        }

        foreach ($context->codebase->checkMultipleFunctionsExist($candidates) as $exists) {
            if ($exists) {
                return;
            }
        }

        foreach ($candidates as $candidate) {
            if (function_exists($candidate)) {
                return;
            }
        }

        $this->report(
            $context,
            $funcArg->span,
            "#[{$label}] target function `{$normalized}` does not exist.",
        );
    }

    private function extractClassReference(SourceFile $source, Node $argument): ?string
    {
        foreach ($source->getResolvedNames($argument) as $resolved) {
            $name = ltrim($resolved->name, '\\');
            if ($name !== '' && strtolower($name) !== 'class') {
                return $name;
            }
        }

        $literal = $this->literalString($source, $argument);
        if ($literal !== null) {
            $trimmed = ltrim(trim($literal), '\\');

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    private function enclosingNamespace(SourceFile $source, Node $node): string
    {
        $current = $source->getParent($node);
        while ($current !== null) {
            if ($current->kind === NodeKind::Namespace) {
                foreach ($source->getChildren($current) as $child) {
                    if (
                        $child->kind === NodeKind::Identifier
                        || $child->kind === NodeKind::LocalIdentifier
                        || $child->kind === NodeKind::QualifiedIdentifier
                    ) {
                        return ltrim(trim($source->getText($child)), '\\');
                    }
                }
            }
            $current = $source->getParent($current);
        }

        return '';
    }

    private function attributeName(SourceFile $source, Node $attribute): ?string
    {
        foreach ($source->getChildren($attribute) as $child) {
            $resolved = $source->getResolvedName($child);
            if ($resolved !== null) {
                return ltrim($resolved->name, '\\');
            }
        }

        return null;
    }

    /**
     * @return list<Node>
     */
    private function attributeArguments(SourceFile $source, Node $attribute): array
    {
        $arguments = [];
        foreach ($source->getChildren($attribute) as $list) {
            if ($list->kind !== NodeKind::PartialArgumentList && $list->kind !== NodeKind::ArgumentList) {
                continue;
            }

            foreach ($source->getChildren($list) as $item) {
                foreach ($source->getChildren($item) as $argument) {
                    if ($argument->kind === NodeKind::PositionalArgument || $argument->kind === NodeKind::NamedArgument) {
                        $arguments[] = $argument;
                    }
                }
            }
        }

        return $arguments;
    }

    /**
     * @param list<Node> $arguments
     */
    private function attributeArgument(SourceFile $source, array $arguments, int $position, string $name): ?Node
    {
        foreach ($arguments as $argument) {
            if ($argument->kind === NodeKind::NamedArgument && $this->argumentName($source, $argument) === $name) {
                return $argument;
            }
        }

        $index = 0;
        foreach ($arguments as $argument) {
            if ($argument->kind === NodeKind::NamedArgument) {
                continue;
            }
            if ($index++ === $position) {
                return $argument;
            }
        }

        return null;
    }

    private function argumentName(SourceFile $source, Node $argument): ?string
    {
        foreach ($source->getChildren($argument) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                return $source->getText($child);
            }
        }

        return null;
    }

    private function literalString(SourceFile $source, ?Node $argument): ?string
    {
        if ($argument === null) {
            return null;
        }

        $literal = $argument->kind === NodeKind::LiteralString
            ? $argument
            : $source->getFirstDescendant($argument, NodeKind::LiteralString);
        if ($literal === null) {
            return null;
        }

        return $source->getLiteralString($literal) ?? $this->decodeQuoted($source->getText($literal));
    }

    private function decodeQuoted(string $text): ?string
    {
        if (strlen($text) < 2) {
            return null;
        }

        $quote = $text[0];
        if (($quote !== "'" && $quote !== '"') || $text[strlen($text) - 1] !== $quote) {
            return null;
        }

        $body = substr($text, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ['\\', "'"], $body);
        }
        if (str_contains($body, '$')) {
            return null;
        }

        return stripcslashes($body);
    }

    private function report(NodeAnalysisContext $context, Span $span, string $message): void
    {
        $context->report(
            Level::Error,
            self::ISSUE_CODE,
            Issue::at($message, new SourceLocation($context->analysis->file, $span)),
        );
    }
}
