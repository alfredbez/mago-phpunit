<?php

declare(strict_types=1);

use AlfredBez\MagoPhpunit\Analyzer\Providers\DataProviderArguments;
use Mago\Sdk\Syntax\NodeKind;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '512M');

$directory = $argv[1] ?? throw new InvalidArgumentException('Pass a directory of trusted local benchmark snapshots');
$iterations = (int) ($argv[2] ?? 5);
$files = glob($directory . '/*.snapshot');
if (count($files) !== 4 || $iterations < 1) {
    throw new InvalidArgumentException('Expected four generated snapshots and positive iterations');
}
$sources = array_map(static fn (string $path) => unserialize(file_get_contents($path)), $files);
$index = new ReflectionMethod(DataProviderArguments::class, 'methodsInFile');
$classes = array_map(static fn ($source) => count($source->getNodes(NodeKind::Class_)), $sources);
$checksum = 0;
$start = hrtime(true);
for ($iteration = 0; $iteration < $iterations; $iteration++) {
    $provider = new DataProviderArguments();
    foreach ($sources as $file => $source) {
        for ($class = 0; $class < $classes[$file]; $class++) {
            $methods = $index->invoke($provider, $source);
            $checksum += count($methods);
            foreach ($methods as $names) {
                if (count($names) !== 27) {
                    throw new RuntimeException('Incomplete benchmark method index');
                }
            }
        }
    }
}
if ($checksum !== 256 * $iterations) {
    throw new RuntimeException('Unexpected method-index checksum');
}
echo json_encode(['elapsed_ms' => (hrtime(true) - $start) / 1e6, 'checksum' => $checksum], JSON_THROW_ON_ERROR) . "\n";
