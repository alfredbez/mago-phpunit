<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;

trait SameFileProviderTrait
{
    /** @return list<array{int, string}> */
    public static function validSameFileTraitRows(): array
    {
        return [
            [1, 'alpha'],
            [2, 'beta'],
        ];
    }

    /** @return list<array<int, mixed>> */
    public static function invalidSameFileTraitRows(): array
    {
        return [
            [1],
            ['bad-same-file-int', 'beta'],
        ];
    }

    /** @return list<array<int, mixed>> */
    public static function dynamicSameFileTraitRows(): array
    {
        return self::buildDynamicSameFileRows();
    }

    /** @return list<array<int, mixed>> */
    private static function buildDynamicSameFileRows(): array
    {
        return [[1, 'dynamic']];
    }
}

final class DataProviderTraitAndInheritedTest extends CrossFileBaseProviderTestCase
{
    use SameFileProviderTrait;
    use CrossFileProviderTrait;
    use CompositeCrossFileProviderTrait;

    #[DataProvider('validSameFileTraitRows')]
    public function testSameFileTraitValid(int $n, string $label): void {}

    #[DataProvider('invalidSameFileTraitRows')]
    public function testSameFileTraitInvalid(int $n, string $label): void {}

    #[DataProvider('dynamicSameFileTraitRows')]
    public function testSameFileTraitDynamic(int $n, string $label): void {}

    #[DataProvider('validTraitRows')]
    public function testCrossFileTraitValid(int $n, string $label): void {}

    #[DataProvider('invalidTraitRows')]
    public function testCrossFileTraitInvalid(int $n, string $label): void {}

    #[DataProvider('dynamicTraitRows')]
    public function testCrossFileTraitDynamic(int $n, string $label): void {}

    #[DataProvider('invalidNestedTraitRows')]
    public function testCrossFileNestedTraitInvalid(int $n, string $label): void {}

    #[DataProvider('validBaseRows')]
    public function testCrossFileInheritedValid(int $n, string $label): void {}

    #[DataProvider('invalidBaseRows')]
    public function testCrossFileInheritedInvalid(int $n, string $label): void {}

    #[DataProvider('dynamicBaseRows')]
    public function testCrossFileInheritedDynamic(int $n, string $label): void {}

    #[DataProviderExternal(ExternalTraitBackedProvider::class, 'validTraitRows')]
    public function testExternalTraitValid(int $n, string $label): void {}

    #[DataProviderExternal(ExternalTraitBackedProvider::class, 'invalidTraitRows')]
    public function testExternalTraitInvalid(int $n, string $label): void {}
}

trait ConflictInvalidProviderTrait
{
    /** @return list<array<int, mixed>> */
    public static function conflictProviderRows(): array
    {
        return [
            ['wrong-type-from-conflict-trait', 'label'],
        ];
    }
}

trait ConflictValidProviderTrait
{
    /** @return list<array{int, string}> */
    public static function conflictProviderRows(): array
    {
        return [
            [7, 'ok'],
        ];
    }
}

final class DataProviderTraitInsteadofValidTest extends CrossFileBaseProviderTestCase
{
    use ConflictInvalidProviderTrait, ConflictValidProviderTrait {
        ConflictValidProviderTrait::conflictProviderRows insteadof ConflictInvalidProviderTrait;
    }

    #[DataProvider('conflictProviderRows')]
    public function testInsteadofSelectsValidTrait(int $n, string $label): void {}
}

final class DataProviderTraitInsteadofInvalidTest extends CrossFileBaseProviderTestCase
{
    use ConflictValidProviderTrait, ConflictInvalidProviderTrait {
        ConflictInvalidProviderTrait::conflictProviderRows insteadof ConflictValidProviderTrait;
    }

    #[DataProvider('conflictProviderRows')]
    public function testInsteadofSelectsInvalidTrait(int $n, string $label): void {}
}
