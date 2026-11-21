<?php

declare(strict_types=1);

if (!is_dir(__DIR__ . '/fixtures')) {
    mkdir(__DIR__ . '/fixtures');
}

for ($file = 0; $file < 4; $file++) {
    $source = <<<'FIXTURE'
<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Bench;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

FIXTURE;
    for ($class = 0; $class < 8; $class++) {
        $name = 'Batch' . $file . 'Case' . $class;
        $source .= "\nfinal class {$name} extends TestCase\n{\n";
        $source .= <<<'FIXTURE'
    private string $ready;

    protected function setUp(): void
    {
        $this->ready = 'ready';
    }

    public function testSeed(): string
    {
        self::assertSame('ready', $this->ready);
        return $this->ready;
    }

    /** @return list<array{int, string}> */
    public static function rows(): array
    {
        return [[0, 'zero'], [1, 'one'], [2, 'two'], [3, 'three']];
    }

FIXTURE;
        for ($test = 0; $test < 24; $test++) {
            $source .= "\n    #[DataProvider('rows')]\n    #[Depends('testSeed')]\n";
            $source .= "    public function testRow{$test}(int \$id, string \$label, string \$seed): void\n    {\n";
            $source .= <<<'FIXTURE'
        $values = $id > 0 ? ['id' => $id, 'label' => $label] : [];
        self::assertArrayHasKey('id', $values);
        self::assertArrayHasKey('label', $values);
        self::assertSame($id, $values['id']);
        self::assertSame($label, $values['label']);
        self::assertSame($this->ready, $seed);
    }

FIXTURE;
        }
        $source .= "}\n";
    }
    file_put_contents(__DIR__ . '/fixtures/Batch' . $file . '.php', $source);
}
