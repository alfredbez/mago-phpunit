<?php

declare(strict_types=1);

namespace Wave8ImportedFactories {
    use Wave8Constructor\ConstructedService;
    use Wave8Constructor\HasMockMethods;

    function importedUnionFactory(): ConstructedService|HasMockMethods
    {
        return new ConstructedService();
    }
}

namespace Wave8Constructor {

use PHPUnit\Framework\TestCase as CaseBase;
use Wave8Constructor\ConstructedService as Target;
use function Wave8ImportedFactories\importedUnionFactory as aliasedUnionFactory;

final class ConstructedService
{
    public function __construct(mixed $argument = null) {}
    public function run(): string { return 'plain'; }
}

final class HasMockMethods
{
    public function method(string $name): void {}
    public function expects(mixed $matcher): void {}
}

final class TargetFactory
{
    public static function create(): ConstructedService
    {
        return new ConstructedService();
    }
}

function aliasedUnionFactory(): ConstructedService
{
    return new ConstructedService();
}

function makeTargetFunction(): ConstructedService
{
    return new ConstructedService();
}

final class ConstructorCalls extends CaseBase
{
    private function makeTarget(): ConstructedService
    {
        return new ConstructedService();
    }

    private function makeUnion(): ConstructedService|HasMockMethods
    {
        return new ConstructedService();
    }

    private function makeMixed(): mixed
    {
        return new ConstructedService();
    }

    public function direct(): void
    {
        (new Target())->method('run'); // constructor-direct-method
        new Target()->expects(null); // constructor-direct-expects
        (((new \Wave8Constructor\ConstructedService)))->method('run'); // constructor-wrapped
        (new Target([fn () => new Target(), ['nested']]))->method('run'); // constructor-arguments
        (new namespace\ConstructedService())->method('run'); // constructor-namespace-relative
    }

    public function assignedMethod(): void
    {
        $plain = new Target();
        $plain->method('run'); // constructor-assigned-method
    }

    public function assignedExpects(): void
    {
        $plain = (new \Wave8Constructor\ConstructedService());
        $plain->expects(null); // constructor-assigned-expects
    }

    public function reference(): void
    {
        $plain = new HasMockMethods();
        $alias =& $plain;
        $plain = new Target();
        $plain->method('run'); // constructor-reference-binding
    }

    public function intervening(): void
    {
        $plain = new Target();
        $another = new HasMockMethods();
        $plain->method('run'); // constructor-intervening-statement
    }

    /** @param class-string<ConstructedService> $class */
    public function dynamic(string $class): void
    {
        (new $class())->method('run'); // constructor-dynamic-class
    }

    public function conditionalExpression(bool $flag): void
    {
        ($flag ? new Target() : new Target())->method('run'); // constructor-conditional-expression
    }

    public function factories(): void
    {
        $this->makeTarget()->method('run'); // constructor-factory-this-direct
        $plain = $this->makeTarget();
        $plain->expects(null); // constructor-factory-this-assigned
        TargetFactory::create()->method('run'); // constructor-factory-static
        makeTargetFunction()->method('run'); // constructor-factory-function
        /** @var Target $annotated */
        $annotated = $this->makeMixed();
        $annotated->method('run'); // constructor-var-single-class
    }

    public function heterogeneousTernary(bool $flag): void
    {
        ($flag ? new Target() : new HasMockMethods())->method('run'); // constructor-heterogeneous-ternary
    }

    public function factoryUnions(): void
    {
        $this->makeUnion()->method('run'); // constructor-factory-union
        $plain = $this->makeUnion();
        $plain->method('run'); // constructor-factory-assigned-union
    }

    public function staleVarReassignedUnion(): void
    {
        /** @var Target $plain */
        $plain = $this->makeMixed();
        $plain = $this->makeUnion();
        $plain->method('run'); // constructor-stale-var-reassigned-union
    }

    /** @param class-string<ConstructedService>|class-string<HasMockMethods> $class */
    public function dynamicUnion(string $class): void
    {
        $instance = new $class();
        $instance->method('run'); // constructor-dynamic-union-class
    }

    /** @param class-string<ConstructedService> $class */
    public function assignedDynamicWithVar(string $class): void
    {
        /** @var class-string<ConstructedService>|class-string<HasMockMethods> $class */
        $plain = new $class();
        $plain->method('run'); // constructor-dynamic-var-union
    }

    public function switchBleed(int $x, ConstructedService|HasMockMethods $plain): void
    {
        switch ($x) {
            case 1:
                $noop = 1;
                $plain = new ConstructedService();
                break;
            case 2:
                $plain->method('run'); // constructor-switch-bleed
                break;
        }
    }

    public function commentedVar(): void
    {
        // @var Target $plain
        $plain = $this->makeUnion();
        $plain->method('run'); // constructor-commented-var
    }

    public function laterVar(bool $flag): void
    {
        $plain = $this->makeUnion();
        if ($flag) {
            /** @var Target $plain */
            $other = 1;
        }
        $plain->method('run'); // constructor-later-var
    }

    public function importedUnionFunction(): void
    {
        aliasedUnionFactory()->method('run'); // constructor-imported-union-fn
    }

    private function sink(mixed $a, mixed $b): void {}

    public function sameStatementAliasMutation(): void
    {
        $plain = new HasMockMethods();
        $alias =& $plain;
        $plain = new Target();
        $this->sink($alias = new HasMockMethods(), $plain->method('run')); // constructor-same-stmt-alias
    }

    public function union(ConstructedService|HasMockMethods $plain): void
    {
        $plain->method('run'); // constructor-real-union
    }

    public function conditional(ConstructedService|HasMockMethods $plain, bool $flag): void
    {
        if ($flag) $plain = new Target();
        $plain->method('run'); // constructor-conditional-assignment
    }

    public function branchMutation(bool $flag): void
    {
        $plain = new Target();
        if ($flag) $plain = new HasMockMethods();
        $plain->method('run'); // constructor-branch-mutation
    }

    public function annotated(): void
    {
        /** @var ConstructedService|HasMockMethods $plain */
        $plain = new Target();
        $plain->method('run'); // constructor-annotated-union
    }

    public function defined(): void
    {
        (new HasMockMethods())->method('run'); // constructor-defined-method
        $plain = new HasMockMethods();
        $plain->expects(null); // constructor-defined-expects
    }
}

final class ApplicationCalls
{
    private function makeTarget(): ConstructedService
    {
        return new ConstructedService();
    }

    public function direct(): void
    {
        (new Target())->method('run'); // constructor-application-direct
        $plain = new Target();
        $plain->expects(null); // constructor-application-assigned
        $this->makeTarget()->method('run'); // constructor-application-factory
    }
}

function globalConstructor(): void
{
    (new Target())->method('run'); // constructor-global-direct
}
}
