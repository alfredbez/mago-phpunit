<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Runtime;

use PHPUnit\Framework\TestCase;

final class AbstractTestDiscoveryRuntimeTest extends TestCase
{
    public function testAbstractPrefixTestIsDiscoveredInSubclassWhileAbstractTestAttributeIsDropped(): void
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/mago-phpunit-abstract-list-' . bin2hex(random_bytes(5));
        mkdir($dir);
        $file = $dir . '/ConcreteContractTest.php';
        file_put_contents($file, <<<'PHP'
<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

abstract class AbstractDiscoveryBase extends TestCase
{
    abstract public function testPrefixContract(): void;

    #[Test]
    abstract public function attributeContract(): void;
}

final class ConcreteContractTest extends AbstractDiscoveryBase
{
    public function testPrefixContract(): void
    {
        self::assertTrue(true);
    }

    public function attributeContract(): void
    {
        self::assertTrue(true);
    }
}
PHP);

        try {
            $process = proc_open(
                [PHP_BINARY, $root . '/vendor/bin/phpunit', '--list-tests', $file],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
            );
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            self::assertSame(0, $exit, $stdout . $stderr);
            self::assertStringContainsString('ConcreteContractTest::testPrefixContract', $stdout);
            self::assertStringNotContainsString('ConcreteContractTest::attributeContract', $stdout);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
}
