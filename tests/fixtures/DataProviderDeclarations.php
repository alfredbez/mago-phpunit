<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class ExternalDeclarationProvider
{
    /** @return list<array{string}> */
    public static function validExternal(): array
    {
        return [['ok']];
    }

    /** @return list<array{string}> */
    public function nonStaticExternal(): array
    {
        return [['ok']];
    }

    /** @return list<array{string}> */
    protected static function protectedExternal(): array
    {
        return [['ok']];
    }
}

abstract class AbstractProviderHostTest extends TestCase
{
    #[DataProvider('subclassRows')]
    public function testSubclassProvided(string $value): void
    {
        self::assertNotSame('', $value);
    }
}

final class ConcreteProviderHostTest extends AbstractProviderHostTest
{
    /** @return list<array{string}> */
    public static function subclassRows(): array
    {
        return [['ok']];
    }
}

final class DataProviderDeclarationsTest extends TestCase
{
    /** @return list<array{string}> */
    public static function validRows(): array
    {
        return [['ok']];
    }

    /** @return list<array{string}> */
    private static function privateRows(): array
    {
        return [['ok']];
    }

    /** @return list<array{string}> */
    protected static function protectedRows(): array
    {
        return [['ok']];
    }

    /** @return list<array{string}> */
    public function nonStaticRows(): array
    {
        return [['ok']];
    }

    #[DataProvider('validRows')]
    public function testValidLocal(string $value): void
    {
        self::assertSame('ok', $value);
    }

    #[DataProviderExternal(ExternalDeclarationProvider::class, 'validExternal')]
    public function testValidExternal(string $value): void
    {
        self::assertSame('ok', $value);
    }

    #[DataProvider('missingRows')]
    public function testMissingLocal(string $value): void
    {
        self::assertSame('ok', $value);
    }

    #[DataProvider('privateRows')]
    public function testPrivateLocal(string $value): void
    {
        self::assertSame('ok', $value);
    }

    #[DataProvider('protectedRows')]
    public function testProtectedLocal(string $value): void
    {
        self::assertSame('ok', $value);
    }

    #[DataProvider('nonStaticRows')]
    public function testNonStaticLocal(string $value): void
    {
        self::assertSame('ok', $value);
    }

    #[DataProvider('ExternalDeclarationProvider::validExternal')]
    public function testInvalidClassColonSyntax(string $value): void
    {
        self::assertSame('ok', $value);
    }

    #[DataProviderExternal(ExternalDeclarationProvider::class, 'missingExternal')]
    public function testMissingExternalMethod(string $value): void
    {
        self::assertSame('ok', $value);
    }

    #[DataProviderExternal(ExternalDeclarationProvider::class, 'nonStaticExternal')]
    public function testNonStaticExternalMethod(string $value): void
    {
        self::assertSame('ok', $value);
    }

    #[DataProviderExternal(ExternalDeclarationProvider::class, 'protectedExternal')]
    public function testProtectedExternalMethod(string $value): void
    {
        self::assertSame('ok', $value);
    }

    #[DataProviderExternal('AlfredBez\\MagoPhpunit\\Fixtures\\MissingExternalProviderClass', 'rows')]
    public function testMissingExternalClassLiteral(string $value): void
    {
        self::assertSame('ok', $value);
    }
}
