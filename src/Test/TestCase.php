<?php

namespace Onion\Framework\Test;

use Onion\Framework\Loop\Scheduler;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;

class TestCase extends PhpUnitTestCase
{
    private string $realTestName;

    final public function setName(string $name): void
    {
        parent::setName($name);
        $this->realTestName = $name;
    }

    final public function runAsyncTest(mixed ...$args)
    {
        parent::setName($this->realTestName);

        scheduler(new Scheduler());
        coroutine(function () use ($args) {
            $this->setUp();
            $this->{$this->realTestName}(...$args);
            $this->tearDown();
        }, $args);
        scheduler()->start();
    }

    final protected function runTest(): void
    {
        parent::setName('runAsyncTest');
        parent::runTest();
    }
}
