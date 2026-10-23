<?php

declare(strict_types=1);

use AlfredBez\MagoPhpunit\PhpUnitExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Worker(PhpUnitExtension::create()))->run();
