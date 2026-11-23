<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/wave8/contracts.php';
chdir(dirname(__DIR__));

$suite = new Wave8Contracts('tests/wave8/global-mutable');
foreach ([
    'stable global parameter' => 'assertisstring',
    'fresh global reference' => 'assertsame',
    'stable global literal' => 'assertsame',
    'fresh global property' => 'assertsame',
    'stable closure parameter' => 'assertisstring',
    'fresh closure expected reference' => 'assertsame',
    'stable global resource' => 'assertisresource',
] as $target => $assertion) {
    $suite->expect('GlobalMutableAssertions.php', $target, 'redundant-assertion', "Assertion {$assertion}() always passes for the static argument type.");
}
foreach ([
    'mutable actual reference',
    'mutable expected reference',
    'mutable actual property',
    'mutable expected property',
    'mutable closure reference',
    'mutable closure actual reference',
] as $target) {
    $suite->expect('GlobalMutableAssertions.php', $target, 'redundant-assertion', 'Assertion assertsame() always passes for the static argument type.', 0);
}
$suite->expect('GlobalMutableAssertions.php', 'mutable global resource', 'redundant-assertion', 'Assertion assertisnotclosedresource() always passes for the static argument type.', 0);
$suite->verify();
