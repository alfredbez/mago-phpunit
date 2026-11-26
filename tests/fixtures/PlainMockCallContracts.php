<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures;

use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;

final class PlainCacheFactory
{
    public function store(string $name): string
    {
        return $name;
    }
}

final class PlainMockCallContractsTest extends TestCase
{
    public function testPositiveExpectsCalls(
        PlainCacheFactory $plain,
        InvocationOrder $matcher,
        mixed $mixedMatcher,
        object $objectMatcher,
    ): void {
        $plain->expects($this->once());
        $plain->expects($matcher);
        $plain->expects($mixedMatcher);
        $plain->expects($objectMatcher);
    }

    public function testPositiveMethodCalls(
        PlainCacheFactory $plain,
        string $name,
        Constraint $constraint,
        mixed $mixedName,
    ): void {
        $plain->method('send');
        $plain->method($name);
        $plain->method($constraint);
        $plain->method($mixedName);
    }

    /** @param array<string, int> $arr */
    public function testNegativeExpectsCalls(
        PlainCacheFactory $plain,
        string $str,
        array $arr,
    ): void {
        $plain->expects('store');
        $plain->expects($str);
        $plain->expects(['a' => 1]);
        $plain->expects($arr);
        $plain->expects(123);
        $plain->expects(null);
        $plain->expects();
        $plain->expects($this->once(), 'extra');
        $plain->expects(new \stdClass());
        $localName = 'store';
        $plain->expects($localName);
    }

    public function testNegativeMethodCalls(
        PlainCacheFactory $plain,
        int $num,
    ): void {
        $plain->method();
        $plain->method('send', 'extra');
        $plain->method(123);
        $plain->method($num);
        $plain->method(null);
        $plain->method(new \stdClass());
        $localNumber = 123;
        $plain->method($localNumber);
    }
}
