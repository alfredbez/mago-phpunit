<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;

/**
 * Validates literal method names passed to PHPUnit MockObject::method() and Stub::method()
 * against the mocked class or interface(s).
 *
 * @internal
 */
final class MockMethodCallHook implements MethodCallAnalysisHook
{
    public const ISSUE_CODE = 'phpunit-unknown-mocked-method';

    private const MOCK_CLASSES = [
        'phpunit\\framework\\mockobject\\mockobject' => true,
        'phpunit\\framework\\mockobject\\stub' => true,
        'phpunit\\framework\\mockobject\\rule\\invocationorder' => true,
        'phpunit\\framework\\mockobject\\builder\\invocationmocker' => true,
    ];

    private const TRAVERSABLE_ITERATOR_METHODS = [
        'current' => true,
        'next' => true,
        'key' => true,
        'valid' => true,
        'rewind' => true,
    ];

    public function getTargets(): array
    {
        return [
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\MockObject', 'method'),
            MethodTarget::exact('PHPUnit\\Framework\\MockObject\\Stub', 'method'),
        ];
    }

    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::ReceiverType,
            FileAnalysisRequirement::ArgumentTypes,
            FileAnalysisRequirement::SourceText,
        ];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $argType = $context->argumentTypes[0] ?? null;
        if (!$argType instanceof Type || !$context->receiverType instanceof Type) {
            return;
        }

        $methodLiterals = self::extractLiteralStrings($argType);
        if ($methodLiterals === []) {
            return;
        }

        $doubleGroups = self::extractDoubleGroups($context->receiverType);
        if ($doubleGroups === []) {
            return;
        }

        foreach ($doubleGroups as $targets) {
            $metadataList = $context->codebase->getMultipleClassLikes($targets);
            $validMetadata = [];
            $magicChecks = [];
            foreach ($metadataList as $index => $meta) {
                if (!$meta instanceof ClassLikeMetadata || $meta->hasIncompleteHierarchy() || $meta->mixins !== []) {
                    continue 2;
                }
                $className = $targets[$index];
                $validMetadata[$className] = $meta;
                $magicChecks[] = new MemberIdentifier($className, '__call');
            }

            $hasMagic = in_array(true, $context->codebase->checkMultipleMethodsExist($magicChecks), true);

            $pseudoIndex = [];
            $allowsTraversableIterator = false;
            foreach ($validMetadata as $className => $meta) {
                foreach ($meta->pseudoMethods as $pseudo) {
                    $pseudoIndex[strtolower($pseudo)] = true;
                }
                if ($this->isDirectTraversableWithoutAggregate($className, $meta)) {
                    $allowsTraversableIterator = true;
                }
            }

            $targetLabel = implode('&', $targets);
            foreach ($methodLiterals as $methodName) {
                if (str_contains($methodName, '::')) {
                    // PHP 8.4 property hook syntax `$prop::get` / `$prop::set`
                    continue;
                }

                $lowerMethod = strtolower($methodName);

                $checks = [];
                foreach ($targets as $className) {
                    $checks[] = new MemberIdentifier($className, $methodName);
                }

                $partial = preg_match('/(?:->|::)\s*(?:getMockBuilder|createPartialMock|onlyMethods|addMethods|getMockForTrait|getMockForAbstractClass)\s*\(/i', $context->source->contents) === 1;
                $exists = $context->codebase->checkMultipleMethodsExist($checks);
                $methods = $context->codebase->getMultipleMethods($checks);
                $reason = null;
                $allowed = false;
                foreach ($methods as $index => $method) {
                    if ($method === null) {
                        if ($exists[$index] ?? false) {
                            $allowed = true;
                        }
                        continue;
                    }
                    if ($method->final || $method->visibility === Visibility::Private || in_array($lowerMethod, ['__construct', '__destruct'], true)) {
                        $reason = 'final, private, constructor, or destructor methods cannot be doubled';
                    } elseif ($method->visibility === Visibility::Protected && !$partial) {
                        $reason = 'protected methods require an explicit partial mock';
                    } else {
                        $allowed = true;
                    }
                }
                if ($reason !== null) {
                    $context->report(
                        Level::Error,
                        self::ISSUE_CODE,
                        Issue::at(
                            "Cannot configure `{$targetLabel}::{$methodName}()` because {$reason}.",
                            new SourceLocation($context->analysis->file, $context->node->span),
                        ),
                    );
                    continue;
                }
                if ($allowed || $partial || $hasMagic || isset($pseudoIndex[$lowerMethod])
                    || ($allowsTraversableIterator && isset(self::TRAVERSABLE_ITERATOR_METHODS[$lowerMethod]))) {
                    continue;
                }

                $context->report(
                    Level::Error,
                    self::ISSUE_CODE,
                    Issue::at(
                        "Trying to mock an undefined method `{$targetLabel}::{$methodName}()`.",
                        new SourceLocation($context->analysis->file, $context->node->span),
                    ),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function extractLiteralStrings(Type $type): array
    {
        $single = $type->getLiteralString();
        if ($single !== null && $single !== '') {
            return [$single];
        }

        $literals = [];
        foreach ($type->atomicTypes as $atomic) {
            $sub = Type::fromAtomic($atomic)->getLiteralString();
            if ($sub === null || $sub === '') {
                return [];
            }
            $literals[] = $sub;
        }

        return array_values(array_unique($literals));
    }

    /**
     * Extracts each union member that represents a typed PHPUnit test double (`MockObject&T` or `Stub&T`).
     *
     * @return list<non-empty-list<string>>
     */
    private static function extractDoubleGroups(Type $receiverType): array
    {
        $groups = [];
        foreach ($receiverType->atomicTypes as $atomic) {
            if (!$atomic instanceof NamedObjectType) {
                continue;
            }

            $members = [$atomic->name];
            foreach ($atomic->intersections ?? [] as $intersection) {
                if ($intersection instanceof NamedObjectType) {
                    $members[] = $intersection->name;
                }
            }

            $hasDoubleMarker = false;
            $targets = [];
            foreach ($members as $member) {
                $lower = strtolower($member);
                if ($lower === 'stdclass') {
                    continue 2;
                }
                if (isset(self::MOCK_CLASSES[$lower])) {
                    $hasDoubleMarker = true;
                    continue;
                }
                $targets[$lower] = $member;
            }

            if ($hasDoubleMarker && $targets !== []) {
                $groups[] = array_values($targets);
            }
        }

        return $groups;
    }

    private function isDirectTraversableWithoutAggregate(string $className, ClassLikeMetadata $meta): bool
    {
        $lower = strtolower($className);
        if ($lower === 'traversable') {
            return true;
        }

        $hasTraversable = false;
        foreach ($meta->parentInterfaces as $iface) {
            $lowerIface = strtolower($iface);
            if ($lowerIface === 'iteratoraggregate') {
                return false;
            }
            if ($lowerIface === 'traversable') {
                $hasTraversable = true;
            }
        }

        return $hasTraversable;
    }
}
