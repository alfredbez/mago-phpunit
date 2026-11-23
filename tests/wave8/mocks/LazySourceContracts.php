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

    function outerAfterShadow(Target|Double $legacy, Target $replacement): void
    {
        $closure = static function (Target|Double $legacy, Target $replacement): void {
            $legacy = $replacement;
            $legacy->method('run'); // lazy-shadow-local-write
        };
        $legacy->method('run'); // lazy-outer-after-shadow-write
    }

    function outerAfterValueCapture(Target|Double $legacy, Target $replacement): void
    {
        $closure = static function () use ($legacy, $replacement): void {
            $legacy = $replacement;
            $legacy->method('run'); // lazy-value-local-write
        };
        $legacy->method('run'); // lazy-outer-after-value-write
    }

    function outerAfterArrow(Target|Double $legacy, Target $replacement): void
    {
        $arrow = fn () => [$legacy = $replacement, $legacy->method('run')]; // lazy-arrow-local-write
        $legacy->method('run'); // lazy-outer-after-arrow-write
    }

    function outerAfterNamed(Target|Double $legacy, Target $replacement): void
    {
        function localBinding(Target|Double $legacy, Target $replacement): void
        {
            $legacy = $replacement;
            $legacy->method('run'); // lazy-named-local-write
        }
        $legacy->method('run'); // lazy-outer-after-named-write
    }

    function outerAfterUncaptured(Target|Double $legacy, Target $replacement): void
    {
        $closure = static function () use ($replacement): void {
            $legacy = $replacement;
        };
        $legacy->method('run'); // lazy-outer-after-uncaptured-write
    }

    function outerAfterNestedNarrowing(Target|Double $legacy): void
    {
        $closure = static function (Target|Double $legacy): void {
            if ($legacy instanceof Double) {}
        };
        $legacy->method('run'); // lazy-outer-after-nested-narrowing
    }

    function outerAfterNestedForeach(Target|Double $legacy, Target $replacement): void
    {
        $closure = static function (Target|Double $legacy, Target $replacement): void {
            foreach ([$replacement] as $legacy) {
                $legacy->method('run'); // lazy-foreach-local-write
            }
        };
        $legacy->method('run'); // lazy-outer-after-nested-foreach
    }

    function outerAfterNestedUnset(Target|Double $legacy): void
    {
        $closure = static function (Target|Double $legacy): void {
            unset($legacy);
        };
        $legacy->method('run'); // lazy-outer-after-nested-unset
    }

    function outerAfterReferenceCapture(Target|Double $legacy, Target $replacement): void
    {
        $closure = static function () use (&$legacy, $replacement): void {
            $legacy = $replacement;
        };
        $legacy->method('run'); // lazy-outer-after-reference-write
    }

    function outerAfterGlobal(Target|Double $legacy, Target $replacement): void
    {
        $closure = static function () use ($replacement): void {
            global $legacy;
            $legacy = $replacement;
        };
        $legacy->method('run'); // lazy-outer-after-global-write
    }

    function outerAfterReferenceParameter(Target|Double $legacy, Target $replacement): void
    {
        $closure = static function (Target|Double &$legacy, Target $replacement): void {
            $legacy = $replacement;
        };
        $legacy->method('run'); // lazy-outer-after-reference-parameter
    }

    function outerAfterReassignment(Target|Double $legacy, Target $replacement): void
    {
        $closure = static function (Target|Double $legacy): void {};
        $legacy = $replacement;
        $legacy->method('run'); // lazy-outer-after-real-write
    }

    final class SharedProperty
    {
        public function __construct(private Target|Double $legacy) {}

        public function nestedClass(Target $replacement): void
        {
            $object = new class ($replacement) {
                public function __construct(private Target $legacy)
                {
                    $this->legacy = $legacy;
                }
            };
            $this->legacy->method('run'); // lazy-property-after-class-write
        }

        public function constructorArgument(Target $replacement): void
        {
            $object = new class ($this->legacy = $replacement) {
                public function __construct(Target $legacy) {}
            };
            $this->legacy->method('run'); // lazy-property-after-constructor-write
        }

        public function nestedWrite(Target $replacement): void
        {
            $closure = function () use ($replacement): void {
                $this->legacy = $replacement;
            };
            $this->legacy->method('run'); // lazy-property-after-nested-write
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
