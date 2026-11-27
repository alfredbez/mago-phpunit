<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Runtime;

use AlfredBez\MagoPhpunit\Fixtures\CrossFileBaseProviderTestCase;
use AlfredBez\MagoPhpunit\Fixtures\CrossFileProviderTrait;
use AlfredBez\MagoPhpunit\Fixtures\ExternalTraitBackedProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;

require_once dirname(__DIR__) . '/fixtures/CrossFileProviderTrait.php';
require_once dirname(__DIR__) . '/fixtures/CrossFileBaseProviderTestCase.php';

trait RuntimeSameFileProviderTrait
{
    /** @return list<array{int, string}> */
    public static function validRuntimeSameFileTraitRows(): array
    {
        return [
            [1, 'alpha'],
            [2, 'beta'],
        ];
    }
}

final class DataProviderTraitAndInheritedRuntimeTest extends CrossFileBaseProviderTestCase
{
    use RuntimeSameFileProviderTrait;
    use CrossFileProviderTrait;

    #[DataProvider('validRuntimeSameFileTraitRows')]
    public function testSameFileTraitProviderRuns(int $n, string $label): void
    {
        self::assertGreaterThan(0, $n);
        self::assertNotSame('', $label);
    }

    #[DataProvider('validTraitRows')]
    public function testCrossFileTraitProviderRuns(int $n, string $label): void
    {
        self::assertGreaterThan(0, $n);
        self::assertNotSame('', $label);
    }

    #[DataProvider('validBaseRows')]
    public function testCrossFileInheritedProviderRuns(int $n, string $label): void
    {
        self::assertGreaterThan(0, $n);
        self::assertNotSame('', $label);
    }

    #[DataProviderExternal(ExternalTraitBackedProvider::class, 'validTraitRows')]
    public function testExternalTraitBackedProviderRuns(int $n, string $label): void
    {
        self::assertGreaterThan(0, $n);
        self::assertNotSame('', $label);
    }

    public function testInvalidTraitAndInheritedProvidersFailWithTypeError(): void
    {
        $root = dirname(__DIR__, 2);
        $target = $root . '/tests/runtime-negative/DataProviderTraitAndInheritedMismatchTest.php';
        $command = [PHP_BINARY, $root . '/vendor/bin/phpunit', $target];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertNotSame(0, $exit, $stdout . $stderr);
        self::assertStringContainsString('testSameFileTraitTypeMismatch', $stdout);
        self::assertStringContainsString('testCrossFileTraitTypeMismatch', $stdout);
        self::assertStringContainsString('testCrossFileInheritedTypeMismatch', $stdout);
        self::assertStringContainsString('TypeError', $stdout);
    }
}
