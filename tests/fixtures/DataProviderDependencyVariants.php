<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\DependsExternalUsingDeepClone;
use PHPUnit\Framework\Attributes\DependsExternalUsingShallowClone;
use PHPUnit\Framework\Attributes\DependsOnClass;
use PHPUnit\Framework\Attributes\DependsOnClassUsingDeepClone;
use PHPUnit\Framework\Attributes\DependsOnClassUsingShallowClone;
use PHPUnit\Framework\Attributes\DependsUsingDeepClone;
use PHPUnit\Framework\Attributes\DependsUsingShallowClone;
use PHPUnit\Framework\TestCase;

final class ExternalProducerTest extends TestCase
{
    public function testExternalValue(): string
    {
        return 'external';
    }
}

final class DataProviderDependencyVariantsTest extends TestCase
{
    public function testLocalValue(): string
    {
        return 'local';
    }

    public static function singleRows(): array
    {
        return [['row']];
    }

    #[DataProvider('singleRows')]
    #[DependsUsingDeepClone('testLocalValue')]
    public function testDependsDeepClone(string $row, string $dep): void
    {
    }

    #[DataProvider('singleRows')]
    #[DependsUsingShallowClone('testLocalValue')]
    public function testDependsShallowClone(string $row, string $dep): void
    {
    }

    #[DataProvider('singleRows')]
    #[DependsExternal(ExternalProducerTest::class, 'testExternalValue')]
    public function testDependsExternal(string $row, string $dep): void
    {
    }

    #[DataProvider('singleRows')]
    #[DependsExternalUsingDeepClone(ExternalProducerTest::class, 'testExternalValue')]
    public function testDependsExternalDeepClone(string $row, string $dep): void
    {
    }

    #[DataProvider('singleRows')]
    #[DependsExternalUsingShallowClone(ExternalProducerTest::class, 'testExternalValue')]
    public function testDependsExternalShallowClone(string $row, string $dep): void
    {
    }

    #[DataProvider('singleRows')]
    #[DependsOnClass(ExternalProducerTest::class)]
    public function testDependsOnClassDoesNotSupplyArgument(string $row, string $missing): void
    {
    }

    #[DataProvider('singleRows')]
    #[DependsOnClassUsingDeepClone(ExternalProducerTest::class)]
    public function testDependsOnClassDeepCloneDoesNotSupplyArgument(string $row, string $missing): void
    {
    }

    #[DataProvider('singleRows')]
    #[DependsOnClassUsingShallowClone(ExternalProducerTest::class)]
    public function testDependsOnClassShallowCloneDoesNotSupplyArgument(string $row, string $missing): void
    {
    }
}
