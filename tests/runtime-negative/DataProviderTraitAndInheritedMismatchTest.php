<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\RuntimeNegative;

use AlfredBez\MagoPhpunit\Fixtures\CrossFileBaseProviderTestCase;
use AlfredBez\MagoPhpunit\Fixtures\CrossFileProviderTrait;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__) . '/fixtures/CrossFileProviderTrait.php';
require_once dirname(__DIR__) . '/fixtures/CrossFileBaseProviderTestCase.php';

trait NegativeSameFileProviderTrait
{
    /** @return list<array<int, mixed>> */
    public static function invalidSameFileTypeRow(): array
    {
        return [
            ['not-an-int', 'label'],
        ];
    }
}

final class DataProviderTraitAndInheritedMismatchTest extends CrossFileBaseProviderTestCase
{
    use NegativeSameFileProviderTrait;
    use CrossFileProviderTrait;

    #[DataProvider('invalidSameFileTypeRow')]
    public function testSameFileTraitTypeMismatch(int $n, string $label): void
    {
        self::assertGreaterThan(0, $n);
        self::assertNotSame('', $label);
    }

    #[DataProvider('invalidTraitRows')]
    public function testCrossFileTraitTypeMismatch(int $n, string $label): void
    {
        self::assertGreaterThan(0, $n);
        self::assertNotSame('', $label);
    }

    #[DataProvider('invalidBaseRows')]
    public function testCrossFileInheritedTypeMismatch(int $n, string $label): void
    {
        self::assertGreaterThan(0, $n);
        self::assertNotSame('', $label);
    }
}
