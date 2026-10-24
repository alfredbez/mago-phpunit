<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit;

use AlfredBez\MagoPhpunit\Analyzer\PhpUnitPlugin;
use Mago\Sdk\Extension;

final class PhpUnitExtension
{
    public const VERSION = '0.1.0';

    private function __construct() {}

    public static function create(): Extension
    {
        return new Extension(
            identifier: 'alfredbez/mago-phpunit',
            name: 'PHPUnit',
            version: self::VERSION,
            analyzerPlugins: [new PhpUnitPlugin()],
        );
    }
}
