<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\DependsOnClass;
use PHPUnit\Framework\Attributes\DependsUsingDeepClone;
use PHPUnit\Framework\Attributes\DependsUsingShallowClone;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\TestWithJson;
use PHPUnit\Framework\TestCase;

final class ExternalRuntimeProvider
{
    /** @return list<array{int, string}> */
    public static function rows(): array
    {
        return [[1, 'one']];
    }
}

final class DependencyProducerRuntimeTest extends TestCase
{
    public function testProduceObject(): stdClass
    {
        $obj = new stdClass();
        $obj->tag = 'external';
        self::assertSame('external', $obj->tag);

        return $obj;
    }
}

final class TestWithAndDependencyVariantsTest extends TestCase
{
    public function testLocalObject(): stdClass
    {
        $obj = new stdClass();
        $obj->tag = 'local';
        self::assertSame('local', $obj->tag);

        return $obj;
    }

    /** @return list<array{string}> */
    public static function oneArg(): array
    {
        return [['row']];
    }

    #[TestWith([1, 'alpha'])]
    #[TestWithJson('[2, "beta"]')]
    public function testTestWithAndJson(int $n, string $label): void
    {
        self::assertGreaterThan(0, $n);
        self::assertNotSame('', $label);
    }

    #[DataProvider('oneArg')]
    #[DependsUsingDeepClone('testLocalObject')]
    public function testDeepCloneDependency(string $row, stdClass $dep): void
    {
        self::assertSame('row', $row);
        self::assertSame('local', $dep->tag);
    }

    #[DataProvider('oneArg')]
    #[DependsUsingShallowClone('testLocalObject')]
    public function testShallowCloneDependency(string $row, stdClass $dep): void
    {
        self::assertSame('row', $row);
        self::assertSame('local', $dep->tag);
    }

    #[DataProvider('oneArg')]
    #[DependsExternal(DependencyProducerRuntimeTest::class, 'testProduceObject')]
    public function testExternalDependency(string $row, stdClass $dep): void
    {
        self::assertSame('row', $row);
        self::assertSame('external', $dep->tag);
    }

    #[DataProvider('oneArg')]
    #[DependsOnClass(DependencyProducerRuntimeTest::class)]
    public function testDependsOnClassPassesNoArgument(string $row): void
    {
        self::assertSame('row', $row);
        self::assertSame(1, func_num_args());
    }

    #[DataProviderExternal(ExternalRuntimeProvider::class, 'rows')]
    public function testCrossClassExternalProvider(int $n, string $label): void
    {
        self::assertSame([1, 'one'], [$n, $label]);
    }

    public function testVoidProducer(): void
    {
        self::assertTrue(true);
    }

    public function testNullableStringProducer(): ?string
    {
        self::assertTrue(true);

        return null;
    }

    #[PHPUnit\Framework\Attributes\Depends('testNullableStringProducer')]
    public function testNullableDependencyPassesNull(?string $value): void
    {
        self::assertNull($value);
    }

    #[PHPUnit\Framework\Attributes\Depends('testVoidProducer')]
    public function testVoidDependencyIsOrderingOnly(): void
    {
        $skipsVoid = method_exists(\PHPUnit\TestRunner\TestResult\PassedTests::class, 'hasReturnValue');
        self::assertSame($skipsVoid ? 0 : 1, func_num_args());
    }

    #[PHPUnit\Framework\Attributes\Depends('testLocalObject')]
    public function testStandaloneDependencyPassesValue(stdClass $dep): void
    {
        self::assertSame('local', $dep->tag);
    }
}
