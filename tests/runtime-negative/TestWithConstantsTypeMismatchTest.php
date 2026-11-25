<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class NegativeProviderConstants
{
    public const int ANSWER = 42;
    public const string TEXT = 'valid';
}

final class TestWithConstantsTypeMismatchTest extends TestCase
{
    #[TestWith([NegativeProviderConstants::ANSWER])]
    public function testIntConstantForStringFailsAtRuntime(string $value): void
    {
        self::assertNotSame('', $value);
    }

    #[TestWith([NegativeProviderConstants::TEXT])]
    public function testStringConstantForIntFailsAtRuntime(int $value): void
    {
        self::assertGreaterThan(0, $value);
    }
}
