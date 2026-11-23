<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/wave8/contracts.php';
chdir(dirname(__DIR__));

$suite = new Wave8Contracts('tests/wave8/mutable');
foreach ([
    'stable parameter' => 'assertisstring',
    'fresh captured assignment' => 'assertsame',
    'fresh property assignment' => 'asserttrue',
    'stable expected variable' => 'assertsame',
    'initial property fact' => 'assertsame',
    'stable expected literal' => 'assertsame',
    'untouched open handle' => 'assertisresource',
    'unrelated call preserves handle' => 'assertisresource',
    '// literal' . "\n" => 'asserttrue',
    'literal string' => 'assertisstring',
    'unrelated reference' => 'assertisint',
] as $target => $assertion) {
    $suite->expect('Wave8MutableAssertions.php', $target, 'redundant-assertion', "Assertion {$assertion}() always passes for the static argument type.");
}
$suite->verify();
