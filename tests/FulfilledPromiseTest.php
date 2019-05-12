<?php
namespace Promise\Tests;

use Onion\Framework\Promise\FulfilledPromise;
use PHPUnit\Framework\TestCase;

class FulfilledPromiseTest extends TestCase
{
    public function testProperState()
    {
        $this->assertTrue((new FulfilledPromise(1))->isFulfilled());
    }
}
