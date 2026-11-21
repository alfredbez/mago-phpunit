<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\DependsExternalUsingDeepClone;
use PHPUnit\Framework\Attributes\DependsExternalUsingShallowClone;
use PHPUnit\Framework\Attributes\DependsUsingDeepClone;
use PHPUnit\Framework\Attributes\DependsUsingShallowClone;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ExternalTypedProducerTest extends TestCase
{
    public function testReturnInt(): int
    {
        return 42;
    }

    public function testReturnString(): string
    {
        return 'ok';
    }
}

final class DependencyReturnTypesTest extends TestCase
{
    public function testIntProducer(): int
    {
        return 7;
    }

    public function testStringProducer(): string
    {
        return 'seed';
    }

    public function testNullableStringProducer(): ?string
    {
        return null;
    }

    public function testVoidProducer(): void
    {
    }

    public function testMixedProducer(): mixed
    {
        return 'anything';
    }

    public function testUntypedProducer()
    {
        return 123;
    }

    /** @return list<array{string}> */
    public static function stringRows(): array
    {
        return [['row']];
    }

    #[Depends('testIntProducer')]
    public function testValidStandalone(int $n): void
    {
    }

    #[Depends('testVoidProducer')]
    public function testOrderingOnlyNoParams(): void
    {
    }

    #[Depends('testVoidProducer')]
    #[Depends('testIntProducer')]
    public function testVoidSkippedBeforeInt(int $n): void
    {
    }

    #[Depends('testNullableStringProducer')]
    public function testNullableIntoNullable(?string $val): void
    {
    }

    #[Depends('testMixedProducer')]
    #[Depends('testUntypedProducer')]
    public function testMixedAndUntypedAreConservative(string $a, int $b): void
    {
    }

    #[DataProvider('stringRows')]
    #[Depends('testIntProducer')]
    #[DependsExternal(ExternalTypedProducerTest::class, 'testReturnString')]
    public function testValidProviderAndDeps(string $row, int $n, string $ext): void
    {
    }

    #[Depends('testIntProducer')]
    public function testMismatchStandalone(string $expected): void
    {
    }

    #[DependsUsingDeepClone('testIntProducer')]
    public function testMismatchDeepClone(string $expected): void
    {
    }

    #[DependsUsingShallowClone('testIntProducer')]
    public function testMismatchShallowClone(string $expected): void
    {
    }

    #[DependsExternal(ExternalTypedProducerTest::class, 'testReturnInt')]
    public function testMismatchExternal(string $expected): void
    {
    }

    #[DependsExternalUsingDeepClone(ExternalTypedProducerTest::class, 'testReturnInt')]
    public function testMismatchExternalDeepClone(string $expected): void
    {
    }

    #[DependsExternalUsingShallowClone(ExternalTypedProducerTest::class, 'testReturnInt')]
    public function testMismatchExternalShallowClone(string $expected): void
    {
    }

    #[Depends('testNullableStringProducer')]
    public function testMismatchNullableIntoNonNull(string $expected): void
    {
    }

    #[DataProvider('stringRows')]
    #[Depends('testIntProducer')]
    public function testMismatchAfterProvider(string $row, string $dep): void
    {
    }

    #[TestWith(['inline'])]
    #[DependsExternal(ExternalTypedProducerTest::class, 'testReturnInt')]
    public function testMismatchAfterTestWith(string $row, string $dep): void
    {
    }
}
