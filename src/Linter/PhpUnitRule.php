<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Linter;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\Safety;
use Mago\Sdk\Reporting\TextEdit;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;

final class PhpUnitRule implements Rule
{
    private const RULES = [
        'strict-assertions' => ['Use strict assertions', 'Prefer identity checks for scalar literals.', true],
        'use-specific-assertions' => [
            'Use specific assertions',
            'Prefer dedicated assertions for null and boolean values.',
            true,
        ],
        'assertion-style-static' => ['Use static assertions', 'Call inherited PHPUnit assertions with static::.', true],
        'assertion-style-self' => ['Use self assertions', 'Call inherited PHPUnit assertions with self::.', false],
        'assertion-style-this' => ['Use instance assertions', 'Call inherited PHPUnit assertions with $this->.', false],
        'prefer-test-attribute' => [
            'Prefer Test attribute',
            'Identify test methods with the PHPUnit Test attribute.',
            false,
        ],
        'no-assert-empty' => [
            'Avoid empty assertions',
            'Prefer assertions describing the expected value or count.',
            false,
        ],
        'data-provider-name-case' => [
            'Match provider name case',
            'Spell data provider references like their declarations.',
            false,
        ],
    ];

    private function __construct(
        private readonly string $rule,
    ) {}

    /** @return list<Rule> */
    public static function all(): array
    {
        return array_map(static fn(string $rule): Rule => new self($rule), array_keys(self::RULES));
    }

    public function getDefinition(): RuleDefinition
    {
        [$name, $description, $enabled] = self::RULES[$this->rule];
        return new RuleDefinition(
            code: 'alfredbez/phpunit/' . $this->rule,
            name: $name,
            description: $description,
            defaultLevel: Level::Warning,
            defaultEnabled: $enabled,
            targets: [NodeKind::Program],
        );
    }

    public function lint(LintContext $context): void
    {
        $file = PhpUnitFile::for($context->file);
        if ($this->rule === 'prefer-test-attribute' || $this->rule === 'data-provider-name-case') {
            $this->lintMethods($context, $file);
            return;
        }
        foreach ($file->calls as $call) {
            match ($this->rule) {
                'strict-assertions' => $this->strictAssertion($context, $call),
                'use-specific-assertions' => $this->specificAssertion($context, $call),
                'no-assert-empty' => $this->emptyAssertion($context, $call),
                default => $this->assertionStyle($context, $file, $call),
            };
        }
    }

    private function strictAssertion(LintContext $context, AssertionCall $call): void
    {
        $replacement = match ($call->name) {
            'assertequals' => 'assertSame',
            'assertnotequals' => 'assertNotSame',
            default => null,
        };
        if ($replacement === null || $call->firstLiteral === null || $call->firstLiteral === 'null') {
            return;
        }
        $context->report(Issue::new(
            "Use `{$replacement}()` to compare a scalar literal strictly.",
            $call->nameSpan,
        )->withEdit($this->rename($call, $replacement, Safety::PotentiallyUnsafe)));
    }

    private function specificAssertion(LintContext $context, AssertionCall $call): void
    {
        $replacement = match ($call->name . ':' . $call->firstLiteral) {
            'assertsame:null', 'assertequals:null' => 'assertNull',
            'assertsame:true', 'assertequals:true' => 'assertTrue',
            'assertsame:false', 'assertequals:false' => 'assertFalse',
            'assertnotsame:null' => 'assertNotNull',
            default => null,
        };
        if ($replacement === null || count($call->arguments) < 2) {
            return;
        }
        $safety = $call->name === 'assertequals' ? Safety::PotentiallyUnsafe : Safety::Safe;
        $issue = Issue::new("Use the dedicated `{$replacement}()` assertion.", $call->nameSpan);
        if ($call->positional) {
            $first = $call->arguments[0];
            $second = $call->arguments[1];
            $removed = $context->file->getText(new Span($first->span->start, $second->span->start));
            $comments = '';
            foreach (token_get_all('<?php ' . $removed) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $comments .= $token[1] . "\n";
                }
            }
            $issue = $issue
                ->withEdit($this->rename($call, $replacement, $safety))
                ->withEdit(TextEdit::replace(
                    new Span($first->span->start, $second->span->start),
                    $comments,
                )->withSafety($safety));
        }
        $context->report($issue);
    }

    private function emptyAssertion(LintContext $context, AssertionCall $call): void
    {
        if (in_array($call->name, ['assertempty', 'assertnotempty'], true)) {
            $context->report(Issue::new(
                'Describe the expected value or count instead of using an empty assertion.',
                $call->nameSpan,
            ));
        }
    }

    private function assertionStyle(LintContext $context, PhpUnitFile $file, AssertionCall $call): void
    {
        $style = substr($this->rule, strlen('assertion-style-'));
        if ($call->style === null || $call->style === $style || $call->receiverSpan === null) {
            return;
        }
        $replacement = $style === 'this' ? '$this->' : $style . '::';
        $issue = Issue::new("Use `{$replacement}` for PHPUnit assertions.", $call->receiverSpan);
        if ($style !== 'this' || $this->hasThis($file, $call->node)) {
            $issue = $issue->withEdit(TextEdit::replace($call->receiverSpan, $replacement)->withSafety(
                Safety::PotentiallyUnsafe,
            ));
        }
        $context->report($issue);
    }

    private function hasThis(PhpUnitFile $file, Node $node): bool
    {
        foreach ($file->file->getAncestors($node) as $ancestor) {
            if (in_array($ancestor->kind, [NodeKind::Method, NodeKind::Closure, NodeKind::ArrowFunction], true)) {
                foreach ($file->file->getChildren($ancestor) as $child) {
                    if (
                        in_array($child->kind, [NodeKind::Modifier, NodeKind::Keyword], true)
                        && strtolower($file->file->getText($child)) === 'static'
                    ) {
                        return false;
                    }
                }
                if ($ancestor->kind === NodeKind::Method) {
                    return true;
                }
            }
        }
        return false;
    }

    private function rename(AssertionCall $call, string $replacement, Safety $safety): TextEdit
    {
        if ($call->node->kind === NodeKind::FunctionCall) {
            $replacement = '\\PHPUnit\\Framework\\' . $replacement;
        }
        return TextEdit::replace($call->nameSpan, $replacement)->withSafety($safety);
    }

    private function lintMethods(LintContext $context, PhpUnitFile $file): void
    {
        foreach ($file->classes as $id => $class) {
            if (!$file->isTestClass($id)) {
                continue;
            }
            foreach ($class['methods'] as $method) {
                if ($this->rule === 'prefer-test-attribute') {
                    $this->testAttribute($context, $file, $id, $method);
                } else {
                    $this->providerName($context, $file, $id, $method);
                }
            }
        }
    }

    private function testAttribute(LintContext $context, PhpUnitFile $file, int $class, Node $method): void
    {
        $name = $file->child($method, NodeKind::LocalIdentifier);
        if ($name === null || !str_starts_with($context->file->getText($name), 'test')) {
            return;
        }
        foreach ($context->file->getChildren($method) as $child) {
            if ($child->kind === NodeKind::Modifier) {
                $modifier = strtolower($context->file->getText($child));
                if (in_array($modifier, ['protected', 'private'], true)) {
                    return;
                }
            }
            if ($child->kind === NodeKind::AttributeList) {
                foreach ($context->file->getChildren($child) as $attribute) {
                    if (
                        strtolower($context->file->getResolvedName($attribute)?->name ?? '')
                        === 'phpunit\\framework\\attributes\\test'
                    ) {
                        return;
                    }
                }
            }
        }
        $replacement = lcfirst(substr($context->file->getText($name), 4));
        $issue = Issue::new('Use `#[Test]` to identify this test method.', $name->span);
        if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $replacement) === 1
            && $file->providerMethod($class, $replacement) === null) {
            $before = substr($context->file->contents, 0, $method->span->start);
            $lineStart = strrpos($before, "\n");
            $indent = substr($before, $lineStart === false ? 0 : $lineStart + 1);
            $indent = trim($indent) === '' ? $indent : '';
            $issue = $issue
                ->withEdit(TextEdit::insert(
                    $method->span->start,
                    "#[\\PHPUnit\\Framework\\Attributes\\Test]\n" . $indent,
                )->withSafety(Safety::PotentiallyUnsafe))
                ->withEdit(TextEdit::replace($name->span, $replacement)->withSafety(Safety::PotentiallyUnsafe));
        }
        $context->report($issue);
    }

    private function providerName(LintContext $context, PhpUnitFile $file, int $class, Node $method): void
    {
        foreach ($context->file->getChildren($method) as $child) {
            if ($child->kind !== NodeKind::AttributeList) {
                continue;
            }
            foreach ($context->file->getChildren($child) as $attribute) {
                if (
                    strtolower($context->file->getResolvedName($attribute)?->name ?? '')
                    !== 'phpunit\\framework\\attributes\\dataprovider'
                ) {
                    continue;
                }
                $list = $file->child($attribute, NodeKind::PartialArgumentList);
                $arguments = $list === null ? [] : $file->arguments($list);
                $argument = $arguments[0] ?? null;
                $value = $argument === null ? null : $file->argumentValue($argument);
                if ($value === null) {
                    continue;
                }
                $provider = $file->stringLiteral($value);
                if ($provider !== null) {
                    $this->reportProvider($context, $file, $class, $provider, $value->span, true);
                }
            }
        }
        foreach ($file->docProviders($method) as $provider) {
            $this->reportProvider($context, $file, $class, $provider['name'], $provider['span'], false);
        }
    }

    private function reportProvider(
        LintContext $context,
        PhpUnitFile $file,
        int $class,
        string $provider,
        Span $span,
        bool $literal,
    ): void {
        $declaration = $file->providerMethod($class, $provider);
        $name = $declaration === null ? null : $file->child($declaration, NodeKind::LocalIdentifier);
        if ($name === null) {
            return;
        }
        $expected = $context->file->getText($name);
        if ($provider === $expected) {
            return;
        }
        $context->report(Issue::new("Use the declared provider name `{$expected}`.", $span)->withEdit(TextEdit::replace(
            $span,
            $literal ? var_export($expected, true) : $expected,
        )));
    }
}
