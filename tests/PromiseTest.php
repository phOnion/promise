<?php
declare(strict_types=1);

namespace Promise\Tests;

use Exception;
use Onion\Framework\Promise\Promise;

class PromiseTest extends \PHPUnit\Framework\TestCase
{
    public function testSuccess()
    {
        $finalValue = 0;
        $promise = new Promise();
        $promise->resolve(1);

        $promise->then(function ($value) use (&$finalValue) {
            $finalValue = $value + 2;
        });

        $this->assertEquals(3, $finalValue);
    }

    public function testFail()
    {
        $finalValue = 0;
        $promise = new Promise();
        $promise->reject(new Exception('1'));

        $promise->then(null, function ($value) use (&$finalValue) {
            $finalValue = $value->getMessage() + 2;
        });

        $this->assertEquals(3, $finalValue);
    }

    public function testChain()
    {
        $finalValue = 0;
        $promise = new Promise();
        $promise->resolve(1);

        $promise = $promise->then(function ($value) use (&$finalValue) {
            $finalValue = $value + 2;

            return $finalValue;
        })->then(function ($value) use (&$finalValue) {
            $finalValue = $value + 4;

            return $finalValue;
        });

        $this->assertEquals(7, $finalValue);
    }

    public function testChainPromise()
    {
        $finalValue = 0;
        $promise = new Promise();
        $promise->resolve(1);

        $subPromise = new Promise();

        $promise = $promise->then(function ($value) use ($subPromise) {
            return $subPromise;
        })->then(function ($value) use (&$finalValue) {
            $finalValue = $value + 4;

            return $finalValue;
        });

        $subPromise->resolve(2);

        $this->assertEquals(6, $finalValue);
    }

    public function testPendingResult()
    {
        $finalValue = 0;
        $promise = new Promise();

        $promise->then(function ($value) use (&$finalValue) {
            $finalValue = $value + 2;
        });

        $promise->resolve(4);

        $this->assertEquals(6, $finalValue);
    }

    public function testPendingFail()
    {
        $finalValue = 0;
        $promise = new Promise();

        $promise->otherwise(function ($value) use (&$finalValue) {
            $finalValue = $value->getMessage() + 2;
        });

        $promise->reject(new Exception('4'));

        $this->assertEquals(6, $finalValue);
    }

    public function testExecutorSuccess()
    {
        $promise = (new Promise(function ($success, $fail) {
            $success('hi');
        }))->then(function ($result) use (&$realResult) {
            $realResult = $result;
        });

        $this->assertEquals('hi', $realResult);
    }

    public function testExecutorFail()
    {
        $promise = (new Promise(function ($success, $fail) {
            $fail(new Exception('hi'));
        }))->then(function ($result) use (&$realResult) {
            $realResult = 'incorrect';
        })->otherwise(function ($reason) use (&$realResult) {
            $realResult = $reason->getMessage();
        });

        $this->assertEquals('hi', $realResult);
    }

    /**
     * @expectedException \LogicException
     */
    public function testFulfillTwice()
    {
        $promise = new Promise();
        $promise->resolve(1);
        $promise->resolve(1);
    }

    /**
     * @expectedException \LogicException
     */
    public function testRejectTwice()
    {
        $promise = new Promise();
        $promise->reject(new Exception('1'));
        $promise->reject(new Exception('1'));
    }

    public function testFromFailureHandler()
    {
        $ok = 0;
        $promise = new Promise();
        $promise->otherwise(function ($reason) {
            $this->assertEquals('foo', $reason);
            throw new \Exception('hi');
        })->then(function () use (&$ok) {
            $ok = -1;
        }, function () use (&$ok) {
            $ok = 1;
        });

        $this->assertEquals(0, $ok);
        $promise->reject(new Exception('foo'));

        $this->assertEquals(1, $ok);
    }

    public function testWaitResolve()
    {
        $promise = new Promise(null, function () use (&$promise) {
            $promise->resolve(1);
        });
        $this->assertEquals(
            1,
            $promise->wait()
        );
    }

    /**
     * @expectedException \LogicException
     */
    public function testWaitWillNeverResolve()
    {
        $promise = new Promise();
        $promise->wait();
    }

    public function testWaitRejectedException()
    {
        $promise = new Promise(null, function () use (&$promise) {
            $promise->reject(new \OutOfBoundsException('foo'));
        });
        try {
            $promise->wait();
            $this->fail('We did not get the expected exception');
        } catch (\Exception $e) {
            $this->assertInstanceOf('OutOfBoundsException', $e);
            $this->assertEquals('foo', $e->getMessage());
        }
    }

    public function testWaitRejectedScalar()
    {
        $promise = new Promise(null, function () use (&$promise) {
            $promise->reject(new Exception('foo'));
        });
        try {
            $promise->wait();
            $this->fail('We did not get the expected exception');
        } catch (\Exception $e) {
            $this->assertInstanceOf('Exception', $e);
            $this->assertEquals('foo', $e->getMessage());
        }
    }
}
