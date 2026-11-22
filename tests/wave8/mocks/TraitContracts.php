<?php

declare(strict_types=1);
namespace Wave8Mocks;
use PHPUnit\Framework\TestCase;
trait WorkerTrait
{
    public function concrete(): string { return 'yes'; }
    abstract public function abstractWork(): string;
}
trait ConcreteWorkerTrait
{
    public function concrete(): string { return 'yes'; }
}
final class TraitContracts extends TestCase
{
    public function testTrait(): string
    {
        $mock = $this->getMockForTrait(WorkerTrait::class);
        $mock->method('abstractWork')->willReturn('ok');
        return $mock->concrete(); // trait-mock-return
    }
    public function testObject(): string
    {
        $object = $this->getObjectForTrait(ConcreteWorkerTrait::class);
        return $object->concrete(); // trait-object-return
    }
}
