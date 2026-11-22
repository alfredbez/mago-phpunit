<?php

declare(strict_types=1);

namespace Wave8Mocks;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

interface Service { public function run(): string; }
interface Other { public function other(): void; }
class Subject
{
    public function okay(): string { return 'ok'; }
    final public function locked(): string { return 'locked'; }
    private function hidden(): void {}
    protected function guarded(): void {}
    public function __construct() {}
    public function __destruct() {}
}
class MagicSubject
{
    final public function locked(): void {}
    private function hidden(): void {}
    public function __call(string $method, array $arguments): mixed { return null; }
}
class ApplicationException extends \RuntimeException {}
class Application
{
    /** @throws \PHPUnit\Framework\AssertionFailedError */
    public function frameworkFailure(): void { throw new \PHPUnit\Framework\AssertionFailedError(); }
}
function acceptsService(Service $service): void {}
function acceptsOther(Other $other): void {}
final class MockContracts extends TestCase
{
    /** @param Service|MockObject $legacy */
    public function testLegacy(Service|MockObject $legacy): void
    {
        $legacy->method('run')->willReturn('yes'); // legacy-method
        $legacy->expects(self::once())->method('run'); // legacy-expects
        acceptsService($legacy); // legacy-pass
        acceptsOther($legacy); // unrelated-pass
    }
    /** @param Service|Other|MockObject $unrelated */
    public function testUnrelated(Service|Other|MockObject $unrelated): void
    {
        acceptsService($unrelated); // union-wrong-target
    }
    public function testShadow(Service|MockObject $legacy): void
    {
        $closure = function (Service|Other $legacy): void {
            $legacy->method('run'); // shadowed-union
        };
        $arrow = fn (Service|Other $legacy) => $legacy->method('run'); // shadowed-arrow
        $inherited = function () use ($legacy): void {
            $legacy->method('run'); // captured-union
        };
    }
    public function testPlain(Service $plain): void
    {
        $plain->method('run'); // plain-method
        $plain->expects(self::once()); // plain-expects
    }
    public function testMethods(): void
    {
        $stub = $this->createStub(Subject::class);
        $magic = $this->createStub(MagicSubject::class);
        $magic->method('locked'); // magic-final
        $magic->method('hidden'); // magic-private
        $magic->method('dynamicMethod'); // magic-dynamic
        $stub->method('okay')->willReturn('fine'); // allowed-method
        $stub->method('locked'); // final-method
        $stub->method('hidden'); // private-method
        $stub->method('guarded'); // protected-method
        $stub->method('__construct'); // constructor-method
        $stub->method('__destruct'); // destructor-method
        $stub->method('absent'); // missing-method
    }
    public function testExceptions(Application $app): void
    {
        $app->frameworkFailure(); // test-framework-exception
        $this->applicationFailure(); // test-application-exception
    }
    /** @throws ApplicationException */
    private function applicationFailure(): void { throw new ApplicationException(); }
}
function outside(Application $app): void
{
    $app->frameworkFailure(); // outside-framework-exception
}
function plainOutside(Service $plain): void
{
    $plain->method('run'); // outside-plain
}
