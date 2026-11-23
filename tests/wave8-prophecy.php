<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/wave8/contracts.php';
chdir(dirname(__DIR__));
if (!class_exists(Prophecy\Prophet::class)) {
    fwrite(STDOUT, "Wave 8 Prophecy contracts skipped without development dependency.\n");
    exit(0);
}
$suite = new Wave8Contracts('tests/wave8/prophecy');
$file = 'Wave8Prophecy.php';
foreach (["find(Argument::type('string'))", "find(Argument::exact('bad'))"] as $target) {
    $suite->expect($file, $target, 'prophecy-token-mismatch', 'Argument token cannot match parameter $id of find().');
}
foreach ([
    ['find(1)->willReturn(new', 'Return value #0 cannot satisfy the return type of `Wave8\Prophecy\Service::find()`.'],
    ['reset()->willReturn(null)', 'Cannot configure willreturn() for `Wave8\Prophecy\Service::reset()` returning void.'],
    ['find(1)->willReturn()', 'Return value #0 cannot satisfy the return type of `Wave8\Prophecy\Service::find()`.'],
    ["same('x')->willReturnArgument(4)", 'willReturnArgument(4) selects no parameter of `Wave8\Prophecy\Service::same()`.'],
    ['$service->objectArgument(new \stdClass())->willReturnArgument', 'Parameter #0 cannot satisfy the return type of `Wave8\Prophecy\Service::objectArgument()`.'],
    ["neverReturns()->willReturn('bad')", 'Cannot configure willreturn() for `Wave8\Prophecy\Service::neverReturns()` returning never.'],
    ['reset()->willYield([])', 'Cannot configure willyield() for `Wave8\Prophecy\Service::reset()` returning void.'],
    ["notGenerator()->willYield(['bad'])", 'willYield() produces a Generator, which `Wave8\Prophecy\Service::notGenerator()` cannot return.'],
    ['$return->willReturn(new', 'Return value #0 cannot satisfy the return type of `Wave8\Prophecy\Service::find()`.'],
    ['$invalid->willReturnArgument(0)', 'Parameter #0 cannot satisfy the return type of `Wave8\\Prophecy\\Service::narrowed()`.'],
    ['$argument->willReturnArgument(0)', 'Parameter #0 cannot satisfy the return type of `Wave8\Prophecy\Service::objectArgument()`.'],
    ['$yield->willYield([])', 'willYield() produces a Generator, which `Wave8\Prophecy\Service::notGenerator()` cannot return.'],
    ["sequence()->willYield('bad')", 'willYield() requires an array of yielded items.'],
    ["same('x')->willReturnArgument('bad')", 'willReturnArgument() requires a non-negative integer index.'],
] as [$target, $message]) {
    $suite->expect($file, $target, 'prophecy-invalid-return', $message);
}
foreach ([
    ['$this->prophesize(FinalService::class)', 'FinalService', 'prophesize'],
    ['$this->prophesize(ServiceTrait::class)', 'ServiceTrait', 'prophesize'],
    ['$this->prophesize(State::class)', 'State', 'prophesize'],
    ['(new \Prophecy\Prophet())->prophesize(FinalService::class)', 'FinalService', 'prophesize'],
    ['$service->willExtend(FinalService::class)', 'FinalService', 'willextend'],
    ['$service->willImplement(ServiceTrait::class)', 'ServiceTrait', 'willimplement'],
    ['$service->willExtend(Service::class)', 'Service', 'willextend'],
    ['$service->willImplement(RestrictedService::class)', 'RestrictedService', 'willimplement'],
] as [$target, $class, $method]) {
    $suite->expect($file, $target, 'prophecy-invalid-double', "Cannot use `Wave8\\Prophecy\\{$class}` with {$method}().");
}
$suite->expect($file, "Argument::type('no_such_primitive')", 'prophecy-unknown-type', 'Argument::type() does not recognize `no_such_primitive`.');
foreach (['fixed', 'privateMethod', 'protectedMethod', 'staticMethod', '_hidden', 'intersection'] as $method) {
    $lower = strtolower($method);
    $suite->expect($file, '$restricted->' . $method . '()', 'prophecy-invalid-setup', "Method `Wave8\\Prophecy\\RestrictedService::{$lower}()` cannot be configured by Prophecy.");
}
$suite->expect($file, '$legacy->find(1)', 'prophecy-union', 'Use ObjectProphecy<Foo> instead of Foo|ObjectProphecy.');
$suite->expectNative($file, '// wrong-chain', 'invalid-return-statement');
$suite->expectNative($file, '// wrong-variable', 'invalid-return-statement');
foreach (['// missing-fluent-argument', '// missing-held-argument'] as $target) {
    $suite->expect($file, $target, 'prophecy-invalid-return', 'Parameter #0 cannot satisfy the return type of `Wave8\\Prophecy\\Service::optional()`.');
}
foreach (['// wrong-union-reveal', '// wrong-receiver-union', '// wrong-union-interface'] as $target) {
    $suite->expectNative($file, $target, 'invalid-return-statement');
}
$suite->verify();
