<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit;

use AlfredBez\MagoPhpunit\Analyzer\PhpUnitPlugin;
use AlfredBez\MagoPhpunit\Linter\PhpUnitRule;
use Mago\Sdk\Analyzer\Plugin as AnalyzerPlugin;
use Mago\Sdk\Extension;
use Mago\Sdk\Linter\Rule;

final class PhpUnitExtension
{
    public const VERSION = '1.1.0';

    private function __construct() {}

    public static function plugin(): AnalyzerPlugin
    {
        return new PhpUnitPlugin();
    }

    /** @return list<Rule> */
    public static function linterRules(): array
    {
        return PhpUnitRule::all();
    }

    public static function create(): Extension
    {
        return new Extension(
            identifier: 'alfredbez/mago-phpunit',
            name: 'PHPUnit',
            version: self::VERSION,
            analyzerPlugins: [self::plugin()],
            linterRules: self::linterRules(),
        );
    }
}
