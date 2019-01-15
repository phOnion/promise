<?php
declare(strict_types=1);

namespace Promise\Tests;

use Exception;
use Onion\Framework\Promise\Promise;

class PromiseTest extends \PHPUnit\Framework\TestCase
{
    public function testSuccess()
    {
        $promise = new Promise();
        $promise->resolve(1);

        $this->assertEquals(3, $promise->then(function ($value) {
            return $value + 2;
        })->await());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage 1
     */
    public function testFail()
    {
        $promise = new Promise();
        $promise->reject(new Exception('1'));

        $promise->then(null, function ($value) {
            return $value->getMessage() + 2;
        })->await();

    }

    public function testChain()
    {
        $promise = new Promise();
        $promise->resolve(1);

        $this->assertEquals(7, $promise->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            return $value + 4;
        })->await());
    }

    public function testChainPromise()
    {
        $subPromise = new Promise();

        $promise = new Promise(null, function () use (&$subPromise) {
            $subPromise->resolve(2);
        });
        $promise->resolve(1);

        $promise = $promise->then(function ($value) use (&$subPromise) {
            return $subPromise;
        })->then(function ($value) {
            return ($value + 4);
        });

        $this->assertTrue($subPromise->isPending());
        $this->assertTrue($promise->isPending());
        // $subPromise->resolve(2);
        $this->assertEquals(6, $promise->await());
    }

    public function testChainCallback()
    {
        $finalValue = 0;
        $promise = new Promise();
        $promise->resolve(1);

        $promise = $promise->then(function ($value) use (&$finalValue) {
            $finalValue = $value + 2;

            return $finalValue;
        })->then(function ($value) use (&$finalValue) {
            return function ($resolve, $reject) use (&$finalValue, $value) {
                $finalValue = $value + 3;

                return $finalValue;
            };
        });

        $this->assertEquals(6, $finalValue);
    }

    public function testChainThenable()
    {
        $promise = new Promise();
        $promise->resolve(1);

        $promise = $promise->then(function ($value) {
            return $value + 2;
        })->then(function ($value) use (&$thenable) {
            return new class($value) {
                private $finalValue;
                public function __construct(&$finalValue) { $this->finalValue = &$finalValue; }
                public function then($resolve, $reject) {
                    $resolve($this->finalValue + 2);
                }
            };
        });

        $this->assertEquals(5, $promise->await());
    }

    public function testPendingResult()
    {
        $promise = (new Promise())
            ->then(function ($value) {
            return $value + 2;
        });

        $promise->resolve(4);

        $this->assertEquals(6, $promise->await());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage 4
     */
    public function testPendingFail()
    {
        $finalValue = 0;
        $promise = (new Promise())
            ->otherwise(function ($value) use (&$finalValue) {
                $finalValue = $value->getMessage() + 2;
            });

        $promise->reject(new Exception('4'));

        $this->assertEquals(6, $finalValue);
        $promise->await();
    }

    public function testExecutorSuccess()
    {
        $promise = (new Promise(function ($success, $fail) {
            $success('hi');
        }))->then(function ($result) {
            return $result;
        });

        $this->assertEquals('hi', $promise->await());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage hi
     */
    public function testExecutorFail()
    {
        $promise = (new Promise(function ($success, $fail) {
            $fail(new Exception('hi'));
        }))->then(function ($result) {
            return 'incorrect';
        })->otherwise(function ($reason) {
            return $reason->getMessage();
        });

        $this->assertTrue($promise->isRejected());
        $promise->await();
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
            $promise->await()
        );
    }

    /**
     * @expectedException \LogicException
     */
    public function testWaitWillNeverResolve()
    {
        $promise = new Promise();
        $promise->await();
    }

    public function testWaitRejectedException()
    {
        $promise = new Promise(null, function () use (&$promise) {
            $promise->reject(new \OutOfBoundsException('foo'));
        });
        try {
            $promise->await();
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
            $promise->await();
            $this->fail('We did not get the expected exception');
        } catch (\Exception $e) {
            $this->assertInstanceOf('Exception', $e);
            $this->assertEquals('foo', $e->getMessage());
        }
    }
}
