<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer\Providers;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\CallExpression;

final class ProphecySupport implements MethodCallAnalysisHook
{
    private const OBJECT = 'Prophecy\\Prophecy\\ObjectProphecy';
    private const METHOD = 'Prophecy\\Prophecy\\MethodProphecy';
    private const OBJECT_METHODS = ['willextend', 'willimplement', 'willbeconstructedwith', 'reveal', 'addmethodprophecy', 'getmethodprophecies', 'makeprophecymethodcall', 'findprophecymethodcalls', 'checkprophecymethodspredictions', '__call', '__get', '__set'];

    public function getTargets(): array
    {
        return [
            MethodTarget::exact('Prophecy\\Prophet', 'prophesize'),
            MethodTarget::exact('Prophecy\\PhpUnit\\ProphecyTrait', 'prophesize'),
            MethodTarget::allMethods(self::OBJECT),
            MethodTarget::exact('Prophecy\\Argument', 'type'),
            MethodTarget::exact(self::METHOD, 'willReturn'),
            MethodTarget::exact(self::METHOD, 'willReturnArgument'),
            MethodTarget::exact(self::METHOD, 'willYield'),
        ];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText, FileAnalysisRequirement::TargetSubtree, FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::ArgumentTypes, FileAnalysisRequirement::ExpressionTypes];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $name = strtolower($context->invocation->name);
        if ($name === 'prophesize') {
            $target = $context->invocation->getArgument(0, 'classOrInterface', 'classOrInterfaceName')?->type?->getLiteralClassString();
            if ($target === null) {
                $target = $context->invocation->getArgument(0, 'classOrInterface', 'classOrInterfaceName')?->type?->getLiteralString();
            }
            if ($target !== null && $context->codebase->classOrInterfaceExists($target)) {
                $target = strcasecmp($target, 'Traversable') === 0 ? 'Iterator' : $target;
                return Type::namedObject(self::OBJECT, Type::namedObject($target));
            }
            return null;
        }
        $revealed = self::revealedType($context->invocation->receiverType);
        $targets = self::targets($context->invocation->receiverType);
        if ($name === 'reveal') {
            return $revealed;
        }
        if (in_array($name, ['willextend', 'willimplement'], true)) {
            $type = $context->invocation->getArgument(0, $name === 'willextend' ? 'class' : 'interface')?->type;
            $target = $type?->getLiteralClassString() ?? $type?->getLiteralString();
            if ($target !== null && $context->codebase->classOrInterfaceExists($target)) {
                $target = strcasecmp($target, 'Traversable') === 0 ? 'Iterator' : $target;
                return Type::namedObject(self::OBJECT, self::mutatedTarget($context, $revealed ?? Type::object(), $target, $name === 'willextend'));
            }
        }
        if ($targets !== [] && !in_array($name, self::OBJECT_METHODS, true)) {
            $arguments = [];
            $anyValues = Type::namedObject('Prophecy\\Argument\\Token\\AnyValuesToken');
            foreach ($context->invocation->arguments as $index => $argument) {
                if ($argument->unpacked || $argument->name !== null || $argument->placeholder
                    || ($argument->type !== null && $context->types->isContainedBy($argument->type, $anyValues))) {
                    return Type::namedObject(self::METHOD, $revealed ?? self::targetType($targets), Type::literalString($context->invocation->name));
                }
                $type = $argument->type ?? Type::mixed();
                if ($this->unknown($type) || $context->types->isContainedBy($type, Type::namedObject('Prophecy\\Argument\\Token\\TokenInterface'))) {
                    $type = Type::mixed();
                }
                $arguments[] = new ArrayItem(new ArrayKey(ArrayKeyKind::Integer, $index), false, $type);
            }
            return Type::namedObject(self::METHOD, $revealed ?? self::targetType($targets), Type::literalString($context->invocation->name),
                Type::fromAtomic(new KeyedArrayType($arguments, null, null, $arguments !== [])));
        }
        return null;
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $call = CallExpression::fromNode($context->source, $context->node);
        $name = strtolower($call->getName($context->source) ?? '');
        if ($name === 'prophesize' || $name === 'willextend' || $name === 'willimplement') {
            $type = $context->argumentTypes[0] ?? null;
            $class = $type?->getLiteralClassString() ?? $type?->getLiteralString();
            if ($class !== null) {
                $meta = $context->codebase->getClassLike($class);
                $invalid = $meta === null || $context->codebase->enumExists($class) || $context->codebase->traitExists($class) || $meta->flags->contains(MetadataFlags::FINAL);
                $invalid = $invalid || ($name === 'willextend' && !$context->codebase->classExists($class)) || ($name === 'willimplement' && !$context->codebase->interfaceExists($class));
                if ($invalid) {
                    $this->report($context, 'prophecy-invalid-double', "Cannot use `{$class}` with {$name}().");
                }
            }
            return;
        }
        if ($name === 'type' && $call->isStaticMethod()) {
            $typeName = ($context->argumentTypes[0] ?? null)?->getLiteralString();
            if ($typeName !== null && $this->tokenType($context, $typeName) === null) {
                $this->report($context, 'prophecy-unknown-type', "Argument::type() does not recognize `{$typeName}`.");
            }
            return;
        }
        if (in_array($name, ['willreturn', 'willreturnargument', 'willyield'], true)) {
            $this->checkReturn($context, $call, $name);
            return;
        }
        if ($this->legacyUnion($context->receiverType)) {
            $this->report($context, 'prophecy-union', 'Use ObjectProphecy<Foo> instead of Foo|ObjectProphecy.');
        }
        $targets = self::targets($context->receiverType);
        if ($targets === [] || in_array($name, self::OBJECT_METHODS, true)) {
            return;
        }
        $foundMethod = false;
        $dynamic = false;
        foreach ($targets as $target) {
            $dynamic = $dynamic || $context->codebase->methodExists($target, '__call');
            $method = $context->codebase->getDeclaringMethod($target, $name);
            if ($method === null) {
                continue;
            }
            $foundMethod = true;
            if ($method->final || $method->static || $method->visibility === Visibility::Private
                || ($method->visibility === Visibility::Protected && !$method->abstract)
                || (str_starts_with($name, '_') && !in_array($name, ['__construct', '__destruct', '__sleep', '__wakeup', '__tostring', '__call', '__invoke'], true))
                || $this->hasIntersection($method->declaredReturnType?->type)) {
                $this->report($context, 'prophecy-invalid-setup', "Method `{$target}::{$name}()` cannot be configured by Prophecy.");
                continue;
            }
            $this->checkTokens($context, $call, $method);
        }
        if (!$foundMethod && !$dynamic) {
            $this->report($context, 'prophecy-invalid-setup', "Cannot prophesize undefined method `{$name}()`.");
        }
    }

    private function checkTokens(NodeAnalysisContext $context, CallExpression $call, FunctionLikeMetadata $method): void
    {
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked) {
                return;
            }
            $parameter = $method->parameters[$argument->index] ?? null;
            if ($argument->name !== null) {
                foreach ($method->parameters as $candidate) {
                    if ($candidate->name === $argument->name) {
                        $parameter = $candidate;
                        break;
                    }
                }
            }
            if ($parameter === null || $parameter->type === null) {
                continue;
            }
            $token = CallExpression::fromExpression($context->source, $argument->value);
            if ($token === null || !$token->isStaticMethod()) {
                continue;
            }
            $resolved = $context->source->getResolvedNames($token->receiver);
            if (strcasecmp(ltrim($resolved[0]->name ?? '', '\\'), 'Prophecy\\Argument') !== 0) {
                continue;
            }
            $tokenName = strtolower($token->getName($context->source) ?? '');
            $value = $token->arguments[0]->value ?? null;
            if ($value === null) {
                continue;
            }
            $valueType = $context->analysis->getExpressionType($value);
            $constraint = match ($tokenName) {
                'type' => ($valueType?->getLiteralString() !== null) ? $this->tokenType($context, $valueType->getLiteralString()) : null,
                'is' => $valueType,
                'exact' => $valueType !== null && ($context->types->isContainedBy($valueType, Type::union(Type::int(), Type::float()))
                    || ($valueType->getLiteralString() !== null && is_numeric($valueType->getLiteralString())))
                    ? Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ScalarType(\Mago\Sdk\Analyzer\Type\ScalarTypeKind::Numeric)) : $valueType,
                default => null,
            };
            if ($constraint !== null && !$context->types->canBeIdentical($constraint, $parameter->type->type)) {
                $this->report($context, 'prophecy-token-mismatch', "Argument token cannot match parameter {$parameter->name} of {$method->originalName}().");
            }
        }
    }

    private function checkReturn(NodeAnalysisContext $context, CallExpression $promise, string $name): void
    {
        foreach ($promise->arguments as $argument) {
            if ($argument->unpacked) {
                return;
            }
        }
        $setup = $promise->receiver !== null ? CallExpression::fromExpression($context->source, $promise->receiver) : null;
        $targets = [];
        $methodName = null;
        $setupArguments = null;
        if ($setup?->receiver !== null) {
            $targets = self::targets($context->analysis->getExpressionType($setup->receiver));
            $methodName = $setup->getName($context->source);
        }
        if ($targets === []) {
            foreach ($context->receiverType?->atomicTypes ?? [] as $atomic) {
                if ($atomic instanceof NamedObjectType && strcasecmp($atomic->name, self::METHOD) === 0 && isset($atomic->parameters[0], $atomic->parameters[1])) {
                    $targets = self::targets(Type::namedObject(self::OBJECT, $atomic->parameters[0]));
                    $methodName = $atomic->parameters[1]->getLiteralString();
                    $setupArguments = $atomic->parameters[2] ?? null;
                }
            }
        }
        if ($targets === [] || $methodName === null) {
            return;
        }
        foreach ($targets as $target) {
            $method = $context->codebase->getDeclaringMethod($target, $methodName);
            $return = $method?->declaredReturnType?->type;
            $methodName = $method?->originalName ?? $methodName;
            if ($method === null || $return === null || $this->unknown($return)) {
                continue;
            }
            foreach ($return->atomicTypes as $atomic) {
                if ($atomic instanceof SimpleAtomicType && in_array($atomic->kind, [SimpleAtomicTypeKind::Void, SimpleAtomicTypeKind::Never], true)) {
                    $this->report($context, 'prophecy-invalid-return', "Cannot configure {$name}() for `{$target}::{$methodName}()` returning {$atomic->kind->value}.");
                    continue 2;
                }
            }
            if ($name === 'willyield') {
                $items = $context->argumentTypes[0] ?? null;
                if ($items !== null && !$this->unknown($items) && !$context->types->isContainedBy($items, Type::array(Type::mixed(), Type::mixed()))
                    && !$context->types->canBeIdentical($items, Type::array(Type::mixed(), Type::mixed()))) {
                    $this->report($context, 'prophecy-invalid-return', 'willYield() requires an array of yielded items.');
                }
                if (!$context->types->isContainedBy(Type::namedObject('Generator'), $return)) {
                    $this->report($context, 'prophecy-invalid-return', "willYield() produces a Generator, which `{$target}::{$methodName}()` cannot return.");
                }
                continue;
            }
            if ($name === 'willreturnargument') {
                $indexType = $context->argumentTypes[0] ?? null;
                $index = $indexType?->getLiteralInt() ?? ($indexType === null ? 0 : null);
                if ($index === null) {
                    if ($indexType !== null && !$this->unknown($indexType) && !$context->types->canBeIdentical($indexType, Type::int())) {
                        $this->report($context, 'prophecy-invalid-return', 'willReturnArgument() requires a non-negative integer index.');
                    }
                    continue;
                }
                $parameter = $method->parameters[$index] ?? null;
                $last = $method->parameters === [] ? null : $method->parameters[array_key_last($method->parameters)];
                if ($parameter === null && $last?->flags->contains(MetadataFlags::VARIADIC)) {
                    $parameter = $last;
                }
                if ($index < 0 || $parameter === null) {
                    if ($index >= 0 && $context->types->isContainedBy(Type::null(), $return)) {
                        continue;
                    }
                    $this->report($context, 'prophecy-invalid-return', "willReturnArgument({$index}) selects no parameter of `{$target}::{$methodName}()`.");
                    continue;
                }
                $selected = $setup?->arguments[$index] ?? null;
                $argumentType = $parameter->type?->type;
                if ($setup !== null && $selected === null) {
                    $complete = true;
                    $anyValues = Type::namedObject('Prophecy\\Argument\\Token\\AnyValuesToken');
                    foreach ($setup->arguments as $argument) {
                        $argType = $context->analysis->getExpressionType($argument->value);
                        $complete = $complete && !$argument->unpacked && $argument->name === null && !$argument->placeholder
                            && ($argType === null || !$context->types->isContainedBy($argType, $anyValues));
                    }
                    if ($complete) {
                        $argumentType = Type::null();
                    }
                }
                foreach ($setupArguments?->atomicTypes ?? [] as $atomic) {
                    if (!$atomic instanceof KeyedArrayType) {
                        continue;
                    }
                    $argumentType = Type::null();
                    foreach ($atomic->knownItems ?? [] as $item) {
                        if ($item->key->kind === ArrayKeyKind::Integer && $item->key->value === $index) {
                            $argumentType = $item->type;
                        }
                    }
                }
                if ($selected !== null && !$selected->unpacked) {
                    $selectedType = $context->analysis->getExpressionType($selected->value);
                    $selectedCall = CallExpression::fromExpression($context->source, $selected->value);
                    if ($selectedCall === null) {
                        $argumentType = $selectedType ?? $argumentType;
                    } elseif ($selectedCall->isStaticMethod() && strtolower($selectedCall->getName($context->source) ?? '') === 'type' && isset($selectedCall->arguments[0])) {
                        $resolved = $context->source->getResolvedNames($selectedCall->receiver);
                        if (strcasecmp(ltrim($resolved[0]->name ?? '', '\\'), 'Prophecy\\Argument') === 0) {
                            $tokenName = $context->analysis->getExpressionType($selectedCall->arguments[0]->value)?->getLiteralString();
                            $argumentType = $tokenName === null ? $argumentType : ($this->tokenType($context, $tokenName) ?? $argumentType);
                        }
                    }
                }
                if ($argumentType !== null && !$this->unknown($argumentType) && !$this->canReturn($context, $argumentType, $return)
                    && !$context->types->canBeIdentical($argumentType, $return)) {
                    $this->report($context, 'prophecy-invalid-return', "Parameter #{$index} cannot satisfy the return type of `{$target}::{$methodName}()`.");
                }
                continue;
            }
            $values = $context->argumentTypes === [] ? [Type::null()] : $context->argumentTypes;
            foreach ($values as $index => $value) {
                if ($value !== null && !$this->unknown($value) && !$this->canReturn($context, $value, $return)) {
                    $this->report($context, 'prophecy-invalid-return', "Return value #{$index} cannot satisfy the return type of `{$target}::{$methodName}()`.");
                }
            }
        }
    }

    private function canReturn(NodeAnalysisContext $context, Type $value, Type $return): bool
    {
        if ($context->types->isContainedBy($value, $return)) {
            return true;
        }
        foreach ($value->atomicTypes as $atomic) {
            $single = Type::fromAtomic($atomic);
            if ($context->types->isContainedBy($single, $return)) {
                continue;
            }
            $accepted = false;
            foreach ($return->atomicTypes as $target) {
                $expected = Type::fromAtomic($target);
                if ($context->types->isContainedBy($expected, Type::string())
                    && ($context->types->isContainedBy($single, Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ScalarType(\Mago\Sdk\Analyzer\Type\ScalarTypeKind::Scalar)))
                        || ($atomic instanceof NamedObjectType && $context->codebase->methodExists($atomic->name, '__toString')))) {
                    $accepted = true;
                }
                if ($context->types->equals($expected, Type::bool())
                    && $context->types->isContainedBy($single, Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ScalarType(\Mago\Sdk\Analyzer\Type\ScalarTypeKind::Scalar)))) {
                    $accepted = true;
                }
                if ($context->types->equals($expected, Type::int()) || $context->types->equals($expected, Type::float())) {
                    $numeric = Type::union(Type::int(), Type::float(), Type::bool());
                    if ($context->types->isContainedBy($single, $numeric)
                        || ($context->types->isContainedBy($single, Type::string()) && ($single->getLiteralString() === null || is_numeric($single->getLiteralString())))) {
                        $accepted = true;
                    }
                }
            }
            if (!$accepted) {
                return false;
            }
        }
        return true;
    }

    private function tokenType(NodeAnalysisContext $context, string $name): ?Type
    {
        $type = match (strtolower($name)) {
            'int', 'integer', 'long' => Type::int(),
            'bool', 'boolean' => Type::bool(),
            'float', 'double', 'real' => Type::float(),
            'string' => Type::string(),
            'null' => Type::null(),
            'array' => Type::array(Type::mixed(), Type::mixed()),
            'object' => Type::object(),
            'scalar' => Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ScalarType(\Mago\Sdk\Analyzer\Type\ScalarTypeKind::Scalar)),
            'numeric' => Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ScalarType(\Mago\Sdk\Analyzer\Type\ScalarTypeKind::Numeric)),
            'iterable' => Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\IterableType(Type::mixed(), Type::mixed(), null)),
            'resource' => Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ResourceType(null)),
            'callable' => Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\CallableType(new \Mago\Sdk\Analyzer\Type\CallableSignature(false, false, [], null, null, []), null)),
            default => null,
        };
        if ($type !== null && $context->codebase->functionExists('is_' . $name)) {
            return $type;
        }
        if ($context->codebase->classOrInterfaceExists($name)) {
            return Type::namedObject($name);
        }
        return $context->codebase->functionExists('is_' . $name) ? Type::mixed() : null;
    }

    private static function revealedType(?Type $receiver): ?Type
    {
        $atoms = [];
        foreach ($receiver?->atomicTypes ?? [] as $atomic) {
            if (!$atomic instanceof NamedObjectType || strcasecmp($atomic->name, self::OBJECT) !== 0) {
                return null;
            }
            array_push($atoms, ...($atomic->parameters[1] ?? $atomic->parameters[0] ?? Type::object())->atomicTypes);
        }
        return $atoms === [] ? null : Type::fromAtomics(...$atoms);
    }

    private static function mutatedTarget(ReturnTypeProviderContext $context, Type $current, string $target, bool $replaceClass): Type
    {
        $alternatives = [];
        foreach ($current->atomicTypes as $atomic) {
            $members = [];
            if ($atomic instanceof NamedObjectType) {
                if (!$replaceClass || $context->codebase->interfaceExists($atomic->name)) {
                    $members[] = new NamedObjectType($atomic->name, $atomic->parameters, $atomic->variances, $atomic->static, $atomic->isThis, null, $atomic->remappedParameters);
                }
                foreach ($atomic->intersections ?? [] as $intersection) {
                    if (!$replaceClass || ($intersection instanceof NamedObjectType && $context->codebase->interfaceExists($intersection->name))) {
                        $members[] = $intersection;
                    }
                }
            }
            $members[] = new NamedObjectType($target, null, null, false, false, null, false);
            $first = array_shift($members);
            $alternatives[] = new NamedObjectType($first->name, $first->parameters, $first->variances, $first->static, $first->isThis, $members === [] ? null : $members, $first->remappedParameters);
        }
        return Type::fromAtomics(...$alternatives);
    }

    /** @return list<string> */
    private static function targets(?Type $type): array
    {
        $targets = [];
        foreach ($type?->atomicTypes ?? [] as $atomic) {
            if (!$atomic instanceof NamedObjectType || strcasecmp($atomic->name, self::OBJECT) !== 0) {
                continue;
            }
            foreach (($atomic->parameters[1] ?? $atomic->parameters[0] ?? null)?->atomicTypes ?? [] as $target) {
                if ($target instanceof NamedObjectType) {
                    $targets[] = $target->name;
                    foreach ($target->intersections ?? [] as $intersection) {
                        if ($intersection instanceof NamedObjectType) {
                            $targets[] = $intersection->name;
                        }
                    }
                }
            }
        }
        return array_values(array_unique($targets));
    }

    /** @param non-empty-list<string> $targets */
    private static function targetType(array $targets): Type
    {
        $primary = array_shift($targets);
        $intersections = array_map(static fn(string $name) => new NamedObjectType($name, null, null, false, false, null, false), $targets);
        return Type::fromAtomic(new NamedObjectType($primary, null, null, false, false, $intersections === [] ? null : $intersections, false));
    }

    private function legacyUnion(?Type $type): bool
    {
        if ($type === null || count($type->atomicTypes) < 2) {
            return false;
        }
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof NamedObjectType && strcasecmp($atomic->name, self::OBJECT) === 0) {
                return true;
            }
        }
        return false;
    }

    private function hasIntersection(?Type $type): bool
    {
        foreach ($type?->atomicTypes ?? [] as $atomic) {
            if ($atomic instanceof NamedObjectType && ($atomic->intersections ?? []) !== []) {
                return true;
            }
        }
        return false;
    }

    private function unknown(Type $type): bool
    {
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof MixedType) {
                return true;
            }
        }
        return false;
    }

    private function report(NodeAnalysisContext $context, string $code, string $message): void
    {
        $context->report(Level::Warning, $code, Issue::at($message, new SourceLocation($context->analysis->file, $context->node->span)));
    }
}
