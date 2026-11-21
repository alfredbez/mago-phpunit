<?php

declare(strict_types=1);

use AlfredBez\MagoPhpunit\Analyzer\Providers\DataProviderArguments;
use Mago\Sdk\Internal\Syntax\NodeStore;
use Mago\Sdk\Internal\Syntax\ResolvedNameStore;
use Mago\Sdk\Internal\Syntax\TriviaStore;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use PHPUnit\Framework\TestCase;

final class DataProviderMethodIndexTest extends TestCase
{
    public function testNewSnapshotAtSamePathHasItsOwnMethods(): void
    {
        $provider = new DataProviderArguments();
        $index = new ReflectionMethod($provider, 'methodsInFile');
        $original = $this->source('firstRows');
        $replacement = $this->source('otherRows');

        self::assertSame(['firstrows'], array_keys($index->invoke($provider, $original)['example']));
        self::assertSame(['otherrows'], array_keys($index->invoke($provider, $replacement)['example']));
        self::assertSame(['firstrows'], array_keys($index->invoke($provider, $original)['example']));
    }

    public function testIndexDoesNotKeepSourceSnapshotAlive(): void
    {
        $provider = new DataProviderArguments();
        $index = new ReflectionMethod($provider, 'methodsInFile');
        $source = $this->source('rows');
        $reference = WeakReference::create($source);
        $methods = $index->invoke($provider, $source);
        unset($source);

        self::assertSame(['rows'], array_keys($methods['example']));
        self::assertNull($reference->get());
    }

    private function source(string $method): SourceFile
    {
        $contents = '<?php class Example { public function ' . $method . '() {} }';
        $methodStart = strpos($contents, $method);
        $none = 4_294_967_295;
        $records = pack('CNNNNN', 0, 6, strlen($contents), $none, 1, $none)
            . pack('CNNNNN', 1, 12, 19, 0, $none, 2)
            . pack('CNNNNN', 2, 22, strlen($contents) - 2, 0, 3, $none)
            . pack('CNNNNN', 1, $methodStart, $methodStart + strlen($method), 2, $none, $none);

        return new SourceFile(
            PHPVersion::fromParts(8, 4),
            '/fixtures/Example.php',
            $contents,
            [0],
            new NodeStore([NodeKind::Class_, NodeKind::LocalIdentifier, NodeKind::Method], $records, 4),
            new ResolvedNameStore(pack('N', 12), pack('NNNC', 19, 0, 7, 0), 'Example', 1),
            new TriviaStore('', 0),
            null,
        );
    }
}
