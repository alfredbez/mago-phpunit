<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

trait CrossFileProviderTrait
{
    /** @return list<array{int, string}> */
    public static function validTraitRows(): array
    {
        return [
            [1, 'one'],
            [2, 'two'],
        ];
    }

    /** @return list<array<int, mixed>> */
    public static function invalidTraitRows(): array
    {
        return [
            [1],
            ['wrong-type', 'two'],
        ];
    }

    /** @return list<array<int, mixed>> */
    public static function dynamicTraitRows(): array
    {
        return self::buildDynamicTraitRows();
    }

    /** @return list<array<int, mixed>> */
    private static function buildDynamicTraitRows(): array
    {
        return [[1, 'dynamic']];
    }
}

trait NestedCrossFileProviderTrait
{
    /** @return list<array<int, mixed>> */
    public static function invalidNestedTraitRows(): array
    {
        return [
            ['bad-nested-int', 'label'],
        ];
    }
}

trait CompositeCrossFileProviderTrait
{
    use NestedCrossFileProviderTrait;
}

final class ExternalTraitBackedProvider
{
    use CrossFileProviderTrait;
}
