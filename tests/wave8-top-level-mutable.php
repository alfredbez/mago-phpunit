<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/wave8/contracts.php';
chdir(dirname(__DIR__));

$suite = new Wave8Contracts('tests/wave8/top-level');
foreach ([
    'fresh top-level value' => 'assertsame',
    'fresh top-level property' => 'assertsame',
    'stable top-level resource' => 'assertisresource',
    'unrelated nested reference' => 'assertsame',
    'stable top-level literal' => 'assertsame',
    'fresh own closure capture' => 'assertsame',
    'fresh across namespace blocks' => 'assertsame',
] as $target => $assertion) {
    $suite->expect('TopLevelAssertions.php', $target, 'redundant-assertion', "Assertion {$assertion}() always passes for the static argument type.");
}
foreach (['changed top-level expected', 'changed top-level actual', 'changed top-level property', 'changed across namespace blocks', 'changed own closure capture'] as $target) {
    $suite->expect('TopLevelAssertions.php', $target, 'redundant-assertion', 'Assertion assertsame() always passes for the static argument type.', 0);
}
$suite->expect('TopLevelAssertions.php', 'changed top-level resource', 'redundant-assertion', 'Assertion assertisnotclosedresource() always passes for the static argument type.', 0);
$suite->verify();
