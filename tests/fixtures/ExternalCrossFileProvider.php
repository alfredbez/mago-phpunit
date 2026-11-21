<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

final class ExternalCrossFileProvider
{
    public static function validRows(): array
    {
        return [
            [1, 'one'],
            [2, 'two'],
        ];
    }

    public static function invalidRows(): array
    {
        return [
            [1],
            ['wrong', 'two'],
            [3, 'three', true],
        ];
    }
}
