<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use AlfredBez\MagoPhpunit\Analyzer\PhpUnitVersion;
use Mago\Sdk\Analyzer\ClassLikeAnalysisHook;
use Mago\Sdk\Analyzer\ClassLikeTarget;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use Mago\Sdk\Syntax\TriviaKind;

final class TestMetadataHook implements ClassLikeAnalysisHook
{
    private const VALUE_TAGS = ['covers', 'coversDefaultClass', 'dataProvider', 'depends', 'testWith', 'requires', 'uses', 'group', 'testdox'];

    private const COMMON_METADATA_ATTRIBUTES = [
        'backupglobals', 'backupstaticproperties', 'coversnothing', 'doesnotperformassertions',
        'excludeglobalvariablefrombackup', 'excludestaticpropertyfrombackup', 'group', 'ignoredeprecations',
        'ignorephpunitdeprecations', 'preserveglobalstate', 'requiresfunction', 'requiresmethod',
        'requiresoperatingsystem', 'requiresoperatingsystemfamily', 'requiresphp', 'requiresphpextension',
        'requiresphpunit', 'requiresphpunitextension', 'requiressetting', 'testdox',
        'ticket',
    ];

    private const CLASS_METADATA_ATTRIBUTES = [
        'coversclass', 'coversfunction', 'coversmethod', 'coverstrait',
        'disablereturnvaluegenerationfortestdoubles', 'ignoreclassforcodecoverage', 'ignorefunctionforcodecoverage', 'ignoremethodforcodecoverage',
        'large', 'medium', 'runclassinseparateprocess', 'runtestsinseparateprocesses',
        'small', 'usesclass', 'usesfunction', 'usesmethod',
        'usestrait',
    ];

    private const METHOD_METADATA_ATTRIBUTES = [
        'after', 'afterclass', 'before', 'beforeclass',
        'dataprovider', 'dataproviderexternal', 'depends', 'dependsexternal',
        'dependsexternalusingdeepclone', 'dependsexternalusingshallowclone', 'dependsonclass', 'dependsonclassusingdeepclone',
        'dependsonclassusingshallowclone', 'dependsusingdeepclone', 'dependsusingshallowclone', 'postcondition',
        'precondition', 'runinseparateprocess', 'test', 'testwith',
        'testwithjson', 'withouterrorhandler',
    ];

    public function getTargets(): array
    {
        return [ClassLikeTarget::descendantsOf('PHPUnit\\Framework\\TestCase')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText, FileAnalysisRequirement::TargetSubtree];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $source = $context->source;
        $className = null;
        foreach ($source->getChildren($context->node) as $child) {
            if ($child->kind === NodeKind::LocalIdentifier) {
                $className = $source->getResolvedName($child)?->name;
                break;
            }
        }
        if ($className === null) {
            return;
        }
        $class = $context->codebase->getClassLike($className);
        $major = PhpUnitVersion::major($context->codebase);
        if ($major >= 12
            && strtolower($class?->directParentClass ?? '') === 'phpunit\\framework\\testcase'
            && stripos($source->contents, 'RequiresPhp') === false) {
            return;
        }
        $this->checkRequires($context, $context->node);
        if ($major <= 11 && !self::hasPhpUnitAttributes($context, $context->node)) {
            $this->checkAnnotations($context, $context->node);
        }
        foreach ($source->getChildren($context->node) as $member) {
            $method = $member->kind === NodeKind::Method ? $member : ($member->kind === NodeKind::ClassLikeMember ? ($source->getChildren($member)[0] ?? null) : null);
            if ($method === null || $method->kind !== NodeKind::Method) {
                continue;
            }
            $this->checkRequires($context, $method);
            if ($major <= 11 && !self::hasPhpUnitAttributes($context, $method)) {
                $this->checkAnnotations($context, $method);
            }
            $methodName = null;
            foreach ($source->getChildren($method) as $child) {
                if ($child->kind === NodeKind::LocalIdentifier) {
                    $methodName = $source->getText($child);
                    break;
                }
            }
            if ($methodName === null || !in_array(strtolower($methodName), ['setup', 'teardown'], true)) {
                continue;
            }
            $parent = $class?->directParentClass;
            if ($parent === null || strtolower($parent) === 'phpunit\\framework\\testcase') {
                continue;
            }
            $parentMethod = $context->codebase->getDeclaringMethod($parent, $methodName);
            if ($parentMethod === null || $parentMethod->abstract || strtolower($parentMethod->identifier->class ?? '') === 'phpunit\\framework\\testcase') {
                continue;
            }
            $called = false;
            foreach ($source->getDescendants($method, NodeKind::StaticMethodCall) as $call) {
                $nested = false;
                foreach ($source->getAncestors($call) as $ancestor) {
                    if ($ancestor->id === $method->id) {
                        break;
                    }
                    if (in_array($ancestor->kind, [NodeKind::Closure, NodeKind::ArrowFunction], true)) {
                        $nested = true;
                        break;
                    }
                }
                if ($nested) {
                    continue;
                }
                $text = $source->getText($call);
                if (preg_match('/^parent\s*::\s*' . preg_quote($methodName, '/') . '\s*\(/i', $text) === 1) {
                    $called = true;
                    break;
                }
            }
            if (!$called) {
                $this->report($context, $method, 'missing-parent-call', "{$methodName}() overrides a parent fixture without calling parent::{$methodName}().");
            }
        }
    }

    private function checkRequires(NodeAnalysisContext $context, Node $container): void
    {
        foreach ($context->source->getChildren($container) as $list) {
            if ($list->kind !== NodeKind::AttributeList) {
                continue;
            }
            foreach ($context->source->getChildren($list) as $attribute) {
                if ($attribute->kind !== NodeKind::Attribute) {
                    continue;
                }
                $name = null;
                foreach ($context->source->getChildren($attribute) as $child) {
                    $name = $context->source->getResolvedName($child)?->name ?? $name;
                }
                if (!in_array(strtolower($name ?? ''), ['phpunit\\framework\\attributes\\requiresphp', 'phpunit\\framework\\attributes\\requiresphpunit'], true)) {
                    continue;
                }
                $literals = $context->source->getDescendants($attribute, NodeKind::LiteralString);
                if (count($literals) !== 1) {
                    continue;
                }
                $version = $context->source->getLiteralString($literals[0]) ?? trim($context->source->getText($literals[0]), "\"' ");
                if ($version === null) {
                    continue;
                }
                if (preg_match('/^\s*(?:>=|<=|!=|==|>|<|=)/', $version) !== 1) {
                    $this->report($context, $attribute, 'requires-without-operator', 'Use an explicit comparison operator in the required version.');
                } elseif ($this->requiresCompleteVersion($context) && preg_match('/^\s*(?:>=|<=|!=|==|>|<|=)\s*\d+\.\d+\.\d+(?:[-+][^\s]+)?\s*$/', $version) !== 1) {
                    $this->report($context, $attribute, 'incomplete-requires-version', 'Write a complete major.minor.patch version in the requirement.');
                }
            }
        }
    }

    private function requiresCompleteVersion(NodeAnalysisContext $context): bool
    {
        return PhpUnitVersion::major($context->codebase) >= 13
            || $context->codebase->classExists('PHPUnit\\Framework\\Attributes\\WithEnvironmentVariable');
    }

    private function checkAnnotations(NodeAnalysisContext $context, Node $node): void
    {
        $doc = self::docblock($context->source, $node);
        if ($doc === null) {
            return;
        }
        foreach (self::VALUE_TAGS as $tag) {
            if (preg_match('/@' . preg_quote($tag, '/') . '(?:(?=[\\\\\\/])|\s*(?=\*\/|(?:\r?\n)\s*\*\s*(?:\r?\n|@|\/)))/i', $doc) === 1) {
                $this->report($context, $node, 'annotation-without-value', "Annotation @{$tag} requires whitespace followed by a value.");
            }
        }
    }

    public static function hasPhpUnitAttributes(NodeAnalysisContext $context, Node $container): bool
    {
        foreach ($context->source->getChildren($container) as $list) {
            if ($list->kind !== NodeKind::AttributeList) {
                continue;
            }
            foreach ($context->source->getChildren($list) as $attribute) {
                if ($attribute->kind !== NodeKind::Attribute) {
                    continue;
                }
                foreach ($context->source->getChildren($attribute) as $child) {
                    $name = $context->source->getResolvedName($child)?->name;
                    if ($name !== null && str_starts_with(strtolower($name), 'phpunit\\framework\\attributes\\')
                        && $context->codebase->classExists($name)
                        && in_array(strtolower(substr($name, (int) strrpos($name, '\\') + 1)), [
                            ...self::COMMON_METADATA_ATTRIBUTES,
                            ...($container->kind === NodeKind::Method ? self::METHOD_METADATA_ATTRIBUTES : self::CLASS_METADATA_ATTRIBUTES),
                        ], true)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public static function docblock(SourceFile $source, Node $node): ?string
    {
        $chosen = null;
        foreach ($source->getTrivia() as $trivia) {
            if ($trivia->kind === TriviaKind::DocBlockComment && $trivia->span->end <= $node->span->start && ($chosen === null || $chosen->span->end < $trivia->span->end)) {
                $chosen = $trivia;
            }
        }
        if ($chosen === null || trim(substr($source->contents, $chosen->span->end, $node->span->start - $chosen->span->end)) !== '') {
            return null;
        }
        return $source->getText($chosen->span);
    }

    private function report(NodeAnalysisContext $context, Node $node, string $code, string $message): void
    {
        $context->report(Level::Warning, $code, Issue::at($message, new SourceLocation($context->analysis->file, $node->span)));
    }
}
