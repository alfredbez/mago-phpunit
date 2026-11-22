<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use AlfredBez\MagoPhpunit\Analyzer\PhpUnitVersion;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
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

        $classDoc = TestMetadataHook::docblock($source, $classNode) ?? '';
        $this->checkAttributeContainer($context, $source, $classNode);
        if (PhpUnitVersion::supportsDocblockMetadata($context->codebase)) {
            $this->checkCoverageAnnotations($context, $classNode, $classDoc, '');
        }
        foreach ($source->getChildren($classNode) as $member) {
            $method = $member->kind === NodeKind::Method
                ? $member
                : ($member->kind === NodeKind::ClassLikeMember ? ($source->getChildren($member)[0] ?? null) : null);
            if ($method !== null && $method->kind === NodeKind::Method) {
                $this->checkAttributeContainer($context, $source, $method);
                if (PhpUnitVersion::supportsDocblockMetadata($context->codebase)) {
                    $this->checkCoverageAnnotations($context, $method, TestMetadataHook::docblock($source, $method) ?? '', $classDoc);
                }
            }
        }
    }

    private function checkAttributeContainer(
        NodeAnalysisContext $context,
        SourceFile $source,
        Node $container,
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
                    $this->checkFunctionTarget($context, $source, $attribute, self::FUNCTION_TARGETS[$lower]);
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

        $meta = $context->codebase->getClassLike($targetClass);
        $major = PhpUnitVersion::major($context->codebase);
        $literal = $this->literalString($source, $classArg);
        if ($meta !== null && ($meta->flags->contains(MetadataFlags::BUILTIN)
            || ($major >= 11 && $context->codebase->interfaceExists($targetClass))
            || ($major >= 12 && ($context->codebase->traitExists($targetClass) || str_starts_with($literal ?? '', '\\'))))) {
            $this->report($context, $classArg->span, "#[{$label}] target `{$targetClass}` is not a coverable class.");
            return;
        }

        if (!$context->codebase->classLikeExists($targetClass)) {
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

        if (!$context->codebase->traitExists($targetTrait) || (PhpUnitVersion::major($context->codebase) >= 12 && str_starts_with($this->literalString($source, $traitArg) ?? '', '\\'))) {
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
        $declaring = $context->codebase->getDeclaringMethod($targetClass, $targetMethod);
        $major = PhpUnitVersion::major($context->codebase);
        if ($meta?->flags->contains(MetadataFlags::BUILTIN) || ($major >= 12 && (
            $context->codebase->interfaceExists($targetClass)
            || str_starts_with($this->literalString($source, $classArg) ?? '', '\\')
            || ($declaring !== null && strcasecmp($declaring->identifier->class ?? '', $targetClass) !== 0
                && !$context->codebase->traitExists($declaring->identifier->class ?? ''))
        ))) {
            $this->report($context, $methodArg->span, "#[{$label}] target `{$targetClass}::{$targetMethod}()` is not declared by a coverable target.");
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

        $function = $context->codebase->getFunction($normalized);
        if ($function !== null && !$function->flags->contains(MetadataFlags::BUILTIN)
            && !(PhpUnitVersion::major($context->codebase) >= 12 && str_starts_with($funcName, '\\'))) {
            return;
        }

        $this->report(
            $context,
            $funcArg->span,
            "#[{$label}] target function `{$normalized}` does not exist.",
        );
    }

    private function checkCoverageAnnotations(NodeAnalysisContext $context, Node $node, string $doc, string $classDoc): void
    {
        $default = null;
        preg_match_all('/@coversDefaultClass[ \t]+([^\s*]+)/i', $classDoc . "\n" . $doc, $defaults);
        if ($classDoc === '' && count($defaults[1]) > 1) {
            $this->report($context, $node->span, 'Duplicate @coversDefaultClass annotations are not allowed.');
        }
        if ($defaults[1] !== []) {
            $default = ltrim($defaults[1][0], '\\');
            if ($classDoc === '' && ($default === '' || !$context->codebase->classLikeExists($default) || $context->codebase->getClassLike($default)?->flags->contains(MetadataFlags::BUILTIN))) {
                $this->report($context, $node->span, "@coversDefaultClass target `{$default}` is not a coverable class.");
            }
        }
        preg_match_all('/@covers[ \t]+([^\s]+)/i', $doc, $targets);
        preg_match_all('/@covers[ \t]+([^\s]+)/i', $classDoc, $classTargets);
        $seen = [];
        foreach ($targets[1] as $raw) {
            $target = ltrim($raw, '\\');
            if (isset($seen[$target])) {
                $this->report($context, $node->span, "Duplicate @covers target `{$raw}`.");
                continue;
            }
            $seen[$target] = true;
            foreach ($classTargets[1] as $classTarget) {
                $normalized = ltrim($classTarget, '\\');
                if ($target === $normalized || (!str_contains($normalized, '::') && str_starts_with($target, $normalized . '::'))) {
                    $context->report(Level::Warning, 'redundant-covers', Issue::at("@covers target `{$raw}` is already declared on the test class.", new SourceLocation($context->analysis->file, $node->span)));
                    break;
                }
            }
            if (str_starts_with($target, '::') && $default !== null) {
                $target = $default . $target;
            }
            if (str_contains($target, '::')) {
                [$class, $method] = explode('::', $target, 2);
                $function = $method === '' ? null : $context->codebase->getFunction($method);
                if ($function !== null && !$function->flags->contains(MetadataFlags::BUILTIN)) {
                    continue;
                }
                if ($class === '' || preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $method) !== 1
                    || !$context->codebase->classLikeExists($class)
                    || $context->codebase->getClassLike($class)?->flags->contains(MetadataFlags::BUILTIN)
                    || !$context->codebase->methodExists($class, $method)) {
                    $this->report($context, $node->span, "@covers target `{$raw}` is not a coverable class method.");
                }
            } else {
                $function = $target === '' ? null : $context->codebase->getFunction($target);
                $validFunction = $function !== null && !$function->flags->contains(MetadataFlags::BUILTIN);
                $validClass = $target !== '' && ($context->codebase->classExists($target) || $context->codebase->traitExists($target))
                    && !$context->codebase->getClassLike($target)?->flags->contains(MetadataFlags::BUILTIN);
                if (!$validFunction && !$validClass) {
                    $this->report($context, $node->span, "@covers target `{$raw}` is not a coverable class, trait, or function.");
                }
            }
        }
    }

    private function extractClassReference(SourceFile $source, Node $argument): ?string
    {
        $expr = $this->unwrapArgumentValue($source, $argument);
        if ($expr === null) {
            return null;
        }

        if ($expr->kind === NodeKind::ClassConstantAccess) {
            $children = $source->getChildren($expr);
            $classNode = $children[0] ?? null;
            $selector = $children[1] ?? null;
            if ($classNode === null || $selector === null || strtolower(trim($source->getText($selector))) !== 'class') {
                return null;
            }

            foreach ($source->getResolvedNames($classNode) as $resolved) {
                $name = ltrim($resolved->name, '\\');
                if ($name !== '' && strtolower($name) !== 'class') {
                    return $name;
                }
            }

            $raw = ltrim(trim($source->getText($classNode)), '\\');

            return $raw !== '' ? $raw : null;
        }

        $literal = $this->literalString($source, $argument);
        if ($literal !== null) {
            $trimmed = ltrim(trim($literal), '\\');

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    private function unwrapArgumentValue(SourceFile $source, Node $argument): ?Node
    {
        $node = $argument;
        if ($node->kind === NodeKind::PositionalArgument) {
            $node = $source->getChildren($node)[0] ?? null;
        } elseif ($node->kind === NodeKind::NamedArgument) {
            $node = $source->getChildren($node)[1] ?? null;
        }

        while (
            $node !== null
            && (
                $node->kind === NodeKind::Expression
                || $node->kind === NodeKind::Access
                || $node->kind === NodeKind::Parenthesized
                || $node->kind === NodeKind::Literal
            )
        ) {
            $node = $source->getChildren($node)[0] ?? null;
        }

        return $node;
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

        $expr = $this->unwrapArgumentValue($source, $argument);
        if ($expr === null || $expr->kind !== NodeKind::LiteralString) {
            return null;
        }

        return $source->getLiteralString($expr) ?? $this->decodeQuoted($source->getText($expr));
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
