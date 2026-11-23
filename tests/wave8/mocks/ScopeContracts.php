<?php

declare(strict_types=1);

namespace Wave8Mocks;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject as ScopeMock;
use Wave8Mocks\Service as ScopeService;

/** @throws \PHPUnit\Exception */
function legacyHelper(Service|MockObject $legacy): void
{
    $legacy->method('run')->willReturn('helper'); // global-legacy
    $legacy->expects(new \PHPUnit\Framework\MockObject\Rule\InvokedCount(1))->method('run'); // global-expects
    acceptsService($legacy); // global-pass
}
trait LegacyHelperTrait
{
    /** @throws \PHPUnit\Exception */
    public function legacyTrait(Service|MockObject $legacy): void
    {
        $legacy->method('run')->willReturn('trait'); // trait-legacy
        acceptsService($legacy); // trait-pass
    }
}
final class LegacyHelper
{
    use LegacyHelperTrait;
    public Service|MockObject $legacy;
    public Service|Other $unrelated;
    public function setup(Service|MockObject $legacy): void
    {
        $legacy->method('run'); // helper-legacy
        $this->legacy->method('run'); // helper-property
        $this->unrelated->method('run'); // helper-unrelated-property
    }
    public function plain(Service $plain): void
    {
        $plain->method('run'); // helper-plain
    }
}
function legacyClosures(Service|MockObject $legacy): void
{
    $closure = function (Service|MockObject $double): void {
        $double->method('run'); // closure-legacy
    };
    $arrow = fn (Service|MockObject $double) => $double->method('run'); // arrow-legacy
    $captured = function () use ($legacy): void {
        $legacy->method('run'); // global-captured
    };
    $shadow = function (Service|Other $legacy): void {
        $legacy->method('run'); // global-shadow
    };
}
function mutatedLegacy(Service|MockObject $legacy, Service|Other $replacement): void
{
    $legacy = $replacement;
    $legacy->method('run'); // mutable-legacy
}
function destructuredLegacy(Service|MockObject $legacy, Service|Other $replacement): void
{
    [$legacy] = [$replacement];
    $legacy->method('run'); // destructured-legacy
}

/**
 * @param Service|MockObject $legacy
 * @throws \PHPUnit\Exception
 */
function documentedLegacy($legacy): void
{
    $legacy->method('run')->willReturn('docblock'); // documented-global
}
trait DocumentedLegacyTrait
{
    /**
 * @param Service|MockObject $legacy
 * @throws \PHPUnit\Exception
 */
    public function documentedLegacy($legacy): void
    {
        $legacy->method('run'); // documented-trait
    }
}
function closureBoundaries(Service|MockObject $legacy): void
{
    $arrow = fn (Service|Other $legacy) => $legacy->method('run'); // sibling-shadow
    $legacy->method('run'); // after-sibling-shadow
    $captured = function () use ($legacy): void {
        $legacy->method('run'); // captured-before-mutation
    };
    $legacy = new class implements Service { public function run(): string { return 'plain'; } };
    $legacy->method('run'); // after-plain-mutation
}
/** @param list<Service|Other> $replacements */
function foreachLegacy(Service|MockObject $legacy, array $replacements): void
{
    foreach ($replacements as $legacy) {
        $legacy->method('run'); // foreach-mutation
    }
}
function referenceLegacy(Service|MockObject $legacy, Service|Other $replacement): void
{
    $alias =& $legacy;
    $alias = $replacement;
    $legacy->method('run'); // reference-mutation
}
function literalLegacy(Service|MockObject $legacy): void
{
    $text = '$legacy = new Other();';
    $legacy->method('run'); // literal-assignment
}
function aliasedLegacy(ScopeService|ScopeMock $legacy): void
{
    $legacy->method('run'); // aliased-legacy
}
function narrowedLegacy(Service|MockObject $legacy): void
{
    if (!$legacy instanceof MockObject) {
        $legacy->method('run'); // narrowed-legacy
    }
}

final class PropertyContracts extends TestCase
{
    /** @var Service|MockObject */
    public $documented;
    public Service|MockObject $legacy;
    public Service|Other $unrelated;
    public Service $plain;
    public function testProperties(): void
    {
        $this->documented->method('run'); // documented-property
        $this->legacy->method('run'); // test-legacy-property
        $this->plain->method('run'); // plain-property
        $this->unrelated->method('run'); // unrelated-property
    }
    public function testPropertyMutation(Service|Other $replacement): void
    {
        $this->legacy = $replacement;
        $this->legacy->method('run'); // property-mutation
    }
}
