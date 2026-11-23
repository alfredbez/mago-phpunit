<?php

declare(strict_types=1);

namespace Wave8LazySource {
    use Wave8Mocks\{Service as Target, Other};
    use PHPUnit\Framework\MockObject\{MockObject as Double};

    function grouped(Target|Double $legacy, string $word): void
    {
        $closure = static function (Target|Double $double): void {
            $double->method('run'); // lazy-grouped-closure
        };
        $arrow = fn (Target|Double $double) => $double->method('run'); // lazy-grouped-arrow
        $default = fn (?Other $legacy = null) => $legacy;
        $legacy->method('run'); // lazy-after-default-shadow
        $text = "{$word}{";
        $text = "{$word})";
        $text = <<<'TEXT'
function fake() { $legacy = new Other(); }
TEXT;
        $text = <<<TEXT
{$word}
{
\$legacy = new Other();
TEXT;
        $legacy->method('run'); // lazy-after-literals
        $text = "{$legacy->method('run')}"; // lazy-interpolated-call
        $captured = function () use ($legacy): void {
            $legacy->method('run'); // lazy-captured-value
        };
        $legacy = new class implements Target { public function run(): string { return 'plain'; } };
        $legacy->method('run'); // lazy-after-mutation
    }

    final class KeywordNames extends \PHPUnit\Framework\TestCase
    {
        public function class(Target|Double $legacy): void
        {
            $legacy->method('run'); // lazy-keyword-class
        }
        public function fn(Target|Double $legacy): void
        {
            $legacy->method('run'); // lazy-keyword-fn
        }
        public function function(Target|Double $legacy): void
        {
            $legacy->method('run'); // lazy-keyword-function
        }
        public function statementBranches(Target|Double $legacy): void
        {
            if (!$legacy instanceof Double)
                do { $legacy->method('run'); } while (false); // lazy-unbraced-do
            if (!$legacy instanceof Double)
                try { $legacy->method('run'); } finally {} // lazy-unbraced-try
            if (!$legacy instanceof Double)
                echo '', $legacy->method('run'); // lazy-unbraced-echo
        }
        public function branch(Target|Double $legacy, bool $flag): void
        {
            if (!$legacy instanceof Double)
                $legacy->method('run'); // lazy-unbraced-negative
            if ($legacy instanceof Double) {
                if ($flag) $legacy->method('run');
                else $legacy->method('run');
            } else
                $legacy->method('run'); // lazy-unbraced-else
        }
    }

    function closureScalar(): void
    {
        $closure = function (Target|Double|string $legacy): void {
            $legacy->method('run'); // lazy-closure-scalar
        };
    }

    function ternaryBoundary(Target|Other $legacy, bool $flag): void
    {
        $choice = $flag ? fn (Target|Double $legacy) => $legacy : $legacy->method('run'); // lazy-ternary-outside-arrow
    }

    function guardBindings(Target|Double $legacy, Target $replacement): void
    {
        if (!($legacy instanceof Double)) {
            $closure = static function (Target|Double $legacy): void {
                $legacy->method('run'); // lazy-guard-shadow-closure
            };
            $arrow = fn (Target|Double $legacy) => $legacy->method('run'); // lazy-guard-shadow-arrow
            $captured = static function () use ($legacy): void {
                $legacy->method('run'); // lazy-guard-captured-value
            };
            $written = static function () use ($legacy, $replacement): void {
                $legacy = $replacement;
                $legacy->method('run'); // lazy-guard-captured-write
            };
        }
    }

    function closureReference(Target|Double $legacy, Target $replacement): void
    {
        $alias =& $legacy;
        $legacy->method('run'); // lazy-outer-reference
        $closure = function () use (&$legacy, $replacement): void {
            $legacy = $replacement;
            $legacy->method('run'); // lazy-mutated-capture
        };
    }

    function branchMutation(Target|Double $legacy, Target $replacement, bool $flag): void
    {
        if ($flag) {
            $legacy = $replacement;
        }
        $legacy->method('run'); // lazy-branch-write
    }
}

namespace Wave8LazyOther {
    use Wave8Mocks\Service as Target;
    use PHPUnit\Framework\MockObject\MockObject as Double;

    /** @param Target|Double $legacy */
    function documented($legacy): void
    {
        $legacy->method('run'); // lazy-second-namespace
    }
}

namespace Wave8FunctionImports {
    use function Wave8Mocks\first, PHPUnit\Framework\MockObject\MockObject;

    interface Service {}
    interface MockObject {}
    function closure(): void
    {
        $closure = function (Service|MockObject $legacy): void {
            $legacy->method('run'); // lazy-function-import
        };
    }
}

namespace Wave8ConstImports {
    use const Wave8Mocks\first, PHPUnit\Framework\MockObject\MockObject;

    interface Service {}
    interface MockObject {}
    function closure(): void
    {
        $closure = function (Service|MockObject $legacy): void {
            $legacy->method('run'); // lazy-const-import
        };
    }
}

namespace Wave8MixedImports {
    use PHPUnit\Framework\MockObject\{function first, const MockObject, MockObject as Double};
    use Wave8Mocks\Service;

    interface MockObject {}
    function closure(): void
    {
        $closure = function (Service|MockObject $legacy): void {
            $legacy->method('run'); // lazy-mixed-import-negative
        };
        $double = function (Service|Double $legacy): void {
            $legacy->method('run'); // lazy-mixed-import-positive
        };
    }
}

namespace Wave8Über {
    use Wave8Mocks\Service;
    use PHPUnit\Framework\MockObject\MockObject;

    function prüfen(Service|MockObject $legacy): void
    {
        $legacy->method('run'); // lazy-unicode-declaration
    }
}
