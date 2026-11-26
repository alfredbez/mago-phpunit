<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Analyzer;

use Mago\Sdk\Analyzer\Codebase;

/**
 * Infers installed PHPUnit major capabilities from stable codebase symbols.
 *
 * @internal
 */
final class PhpUnitVersion
{
    private const TEST_CASE = 'PHPUnit\\Framework\\TestCase';

    private const ASSERT = 'PHPUnit\\Framework\\Assert';

    private const ANNOTATION_PARSER = 'PHPUnit\\Metadata\\Parser\\AnnotationParser';

    private const DATA_PROVIDER_CLOSURE = 'PHPUnit\\Framework\\Attributes\\DataProviderClosure';

    private const PASSED_TESTS = 'PHPUnit\\TestRunner\\TestResult\\PassedTests';

    /** Added in PHPUnit 11.0.0 alongside named argument unpacking from data providers. */
    private const PHPUNIT_11_METHOD = 'expectUserDeprecationMessage';

    /** Added in PHPUnit 13.0.0. */
    private const PHPUNIT_13_METHOD = 'assertArraysAreIdentical';

    private function __construct() {}

    public static function major(Codebase $codebase): int
    {
        if (!$codebase->classExists(self::TEST_CASE)) {
            return 0;
        }

        if (
            $codebase->methodExists(self::ASSERT, self::PHPUNIT_13_METHOD)
            || $codebase->classExists(self::DATA_PROVIDER_CLOSURE)
        ) {
            return 13;
        }

        if (!$codebase->classExists(self::ANNOTATION_PARSER)) {
            return 12;
        }

        if ($codebase->methodExists(self::TEST_CASE, self::PHPUNIT_11_METHOD)) {
            return 11;
        }

        return 10;
    }

    public static function supportsNamedProviderArguments(Codebase $codebase): bool
    {
        return $codebase->methodExists(self::TEST_CASE, self::PHPUNIT_11_METHOD);
    }

    public static function supportsDocblockMetadata(Codebase $codebase): bool
    {
        return $codebase->classExists(self::ANNOTATION_PARSER);
    }

    public static function supportsDataProviderClosure(Codebase $codebase): bool
    {
        return $codebase->classExists(self::DATA_PROVIDER_CLOSURE);
    }

    public static function skipsVoidDependencyReturnValue(Codebase $codebase): bool
    {
        return $codebase->methodExists(self::PASSED_TESTS, 'hasReturnValue');
    }
}
