<?php
namespace Promise\Tests;

use Onion\Framework\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;

class RejectedPromiseTest extends TestCase
{
    public function testProperState()
    {
        $this->assertTrue((new RejectedPromise(new \Exception('1')))->isRejected());
    }
}
