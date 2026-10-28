<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures\Functions;

use function PHPUnit\Framework\assertArrayHasKey;

/** @param array{token?: string|null} $values */
function assertedKey(array $values): string|null
{
    assertArrayHasKey('token', $values);
    return $values['token'];
}

/** @param array{token?: string|null} $values */
function unassertedKey(array $values): string|null
{
    return $values['token'];
}

/** @param array{token?: string|null} $values */
function assertedKeyIsStillNullable(array $values): string
{
    assertArrayHasKey('token', $values);
    return $values['token'];
}

/** @param array{7?: string|null} $values */
function integerKey(array $values): string|null
{
    assertArrayHasKey(7, $values);
    return $values[7];
}

/** @param array{token?: string|null} $values */
function reorderedNamedArguments(array $values): string|null
{
    assertArrayHasKey(array: $values, key: 'token');
    return $values['token'];
}

/** @param array{token?: string|null, other?: string|null} $values */
function otherKey(array $values): string|null
{
    assertArrayHasKey('token', $values);
    return $values['other'];
}

/** @param array{token?: string|null} $values */
function variableKey(array $values, string $key): string|null
{
    assertArrayHasKey($key, $values);
    return $values['token'];
}

namespace AlfredBez\MagoPhpunit\Fixtures\Unrelated;

function assertArrayHasKey(string $key, array $array): void {}

/** @param array{token?: string|null} $values */
function unrelatedAssertion(array $values): string|null
{
    assertArrayHasKey('token', $values);
    return $values['token'];
}
