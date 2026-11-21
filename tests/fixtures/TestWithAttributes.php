<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\TestWithJson;
use PHPUnit\Framework\TestCase;

final class TestWithAttributesTest extends TestCase
{
    public function testSeed(): string
    {
        return 'seed';
    }

    #[TestWith([1, 'ok'])]
    #[TestWith([2])]
    #[TestWith([3, 'ok', true])]
    #[TestWith(['wrong', 'ok'])]
    public function testPositionalTestWith(int $n, string $label): void
    {
    }

    #[TestWith(['n' => 1, 'label' => 'ok'])]
    #[TestWith(['n' => 2])]
    #[TestWith(['n' => 3, 'label' => 'ok', 'extra' => true])]
    #[TestWith(['n' => 'wrong', 'label' => 'ok'])]
    public function testNamedTestWith(int $n, string $label): void
    {
    }

    #[TestWith([1])]
    #[Depends('testSeed')]
    public function testTestWithAndDependency(int $n, string $seed): void
    {
    }

    #[TestWithJson('[1, "ok"]')]
    #[TestWithJson('[2]')]
    #[TestWithJson('[3, "ok", true]')]
    #[TestWithJson('["wrong", "ok"]')]
    #[TestWithJson('{"n": 4, "label": "ok"}')]
    #[TestWithJson('{"n": 5}')]
    #[TestWithJson('{invalid')]
    #[TestWithJson('42')]
    public function testTestWithJson(int $n, string $label): void
    {
    }
}
