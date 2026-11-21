<?php

declare(strict_types=1);

namespace AlfredBez\MagoPhpunit\Fixtures\BeforeHooks;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before as Initialize;
use PHPUnit\Framework\TestCase;

final class BeforePositiveTest extends TestCase
{
    private string $ready;

    #[Initialize]
    protected function prepare(): void
    {
        $this->ready = 'ready';
    }

    public function testReady(): string
    {
        return $this->ready;
    }
}

class BeforeBaseTest extends TestCase
{
    protected string $inherited;

    #[Initialize]
    protected function prepare(): void
    {
        $this->inherited = 'inherited';
    }
}

final class BeforeInheritedTest extends BeforeBaseTest
{
    public function testInherited(): string
    {
        return $this->inherited;
    }
}

final class BeforeHelperTest extends TestCase
{
    private string $ready;
    private string $second;

    #[Initialize]
    protected function prepare(): void
    {
        $this->assign();
    }

    #[Initialize]
    protected function prepareSecond(): void
    {
        $this->second = 'second';
    }

    private function assign(): void
    {
        $this->ready = 'ready';
    }

    public function testReady(): string
    {
        return $this->ready . $this->second;
    }
}

final class BeforeMissingAssignmentTest extends TestCase
{
    private string $missing;

    #[Initialize]
    protected function prepare(): void {}

    public function testMissing(): string
    {
        return $this->missing;
    }
}

final class AfterDoesNotInitializeTest extends TestCase
{
    private string $late;

    #[After]
    protected function finish(): void
    {
        $this->late = 'late';
    }

    public function testLate(): string
    {
        return $this->late;
    }
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Before {}

final class UnrelatedBeforeTest extends TestCase
{
    private string $unrelated;

    #[Before]
    protected function prepare(): void
    {
        $this->unrelated = 'unrelated';
    }

    public function testUnrelated(): string
    {
        return $this->unrelated;
    }
}

final class BeforeNotTestCase
{
    private string $ordinary;

    public function __construct() {}

    #[Initialize]
    public function prepare(): void
    {
        $this->ordinary = 'ordinary';
    }

    public function read(): string
    {
        return $this->ordinary;
    }
}
