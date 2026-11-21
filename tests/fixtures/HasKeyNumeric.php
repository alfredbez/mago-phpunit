<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures\NumericKeys;

use PHPUnit\Framework\Assert;

use function PHPUnit\Framework\assertArrayHasKey;

/** @param array{7?: string} $values */
function methodStringKey(array $values): string
{
    Assert::assertArrayHasKey('7', $values);

    return $values[7];
}

/** @param array{'7'?: string} $values */
function methodIntegerKey(array $values): string
{
    Assert::assertArrayHasKey(7, $values);

    return $values['7'];
}

/** @param array{7?: string} $values */
function functionStringKey(array $values): string
{
    assertArrayHasKey('7', $values);

    return $values[7];
}

/** @param array{'7'?: string} $values */
function functionIntegerKey(array $values): string
{
    assertArrayHasKey(7, $values);

    return $values['7'];
}

/** @param array{'07'?: string} $values */
function leadingZeroKey(array $values): string
{
    Assert::assertArrayHasKey('07', $values);

    return $values['07'];
}

/** @param array{7?: string, '07'?: string} $values */
function leadingZeroDoesNotProveInteger(array $values): ?string
{
    Assert::assertArrayHasKey('07', $values);

    return $values[7];
}

/** @param array{7?: string, '07'?: string} $values */
function integerDoesNotProveLeadingZero(array $values): ?string
{
    assertArrayHasKey(7, $values);

    return $values['07'];
}

/** @param array{7?: string} $values */
function unassertedInteger(array $values): ?string
{
    return $values[7];
}
