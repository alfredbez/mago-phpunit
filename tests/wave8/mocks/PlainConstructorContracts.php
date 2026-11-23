<?php

declare(strict_types=1);

namespace Wave8Constructor;

use PHPUnit\Framework\TestCase as CaseBase;
use Wave8Constructor\ConstructedService as Target;

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

final class ConstructorCalls extends CaseBase
{
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
    public function direct(): void
    {
        (new Target())->method('run'); // constructor-application-direct
        $plain = new Target();
        $plain->expects(null); // constructor-application-assigned
    }
}

function globalConstructor(): void
{
    (new Target())->method('run'); // constructor-global-direct
}
