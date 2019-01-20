<?php
declare(strict_types=1);

namespace Promise\Tests;

use Exception;
use Onion\Framework\Promise\Promise;
use Onion\Framework\Promise\FulfilledPromise;
use Onion\Framework\Promise\RejectedPromise;

class PromiseTest extends \PHPUnit\Framework\TestCase
{
    public function testSuccess()
    {
        $this->assertEquals(3, (new FulfilledPromise(1))->then(function ($value) {
            return $value + 2;
        })->await());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage 1
     */
    public function testFail()
    {
        (new RejectedPromise(new Exception('1')))
            ->then(null, function ($value) {
                return $value;
            })->await();

    }

    public function testChain()
    {
        $this->assertEquals(7, (new FulfilledPromise(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            return $value + 4;
        })->await());
    }

    public function testChainPromise()
    {
        $promise = (new FulfilledPromise(1))->then(function ($value) {
            return new FulfilledPromise(2);
        })->then(function ($value) {
            return ($value + 4);
        });

        $this->assertEquals(6, $promise->await());
    }

    public function testChainCallback()
    {
        $finalValue = 0;

        (new FulfilledPromise(1))->then(function ($value) use (&$finalValue) {
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
        $promise = (new FulfilledPromise(1))->then(function ($value) {
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
        $promise = (new FulfilledPromise(4))
            ->then(function ($value) {
            return $value + 2;
        });

        $this->assertEquals(6, $promise->await());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage 4
     */
    public function testPendingFail()
    {
        $finalValue = 0;
        $promise = (new RejectedPromise(new Exception('4')))
            ->otherwise(function ($value) use (&$finalValue) {
                $finalValue = $value->getMessage() + 2;
            });

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
            return $reason;
        });

        $this->assertTrue($promise->isRejected());
        $promise->await();
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage 2
     */
    public function testRejectTwice()
    {
        $promise = (new RejectedPromise(new Exception('1')))
            ->otherwise(function () {
                throw new Exception('2');
            })->await();
    }

    public function testFromFailureHandler()
    {
        $ok = 0;
        $promise = new RejectedPromise(new Exception('foo'));
        $promise->otherwise(function ($reason) {
            $this->assertEquals('foo', $reason->getMessage());
            throw new \Exception('hi');
        })->then(function () use (&$ok) {
            $ok = -1;
        }, function () use (&$ok) {
            $ok = 1;
        });

        $this->assertEquals(1, $ok);
    }

    public function testWaitResolve()
    {
        $this->assertEquals(
            1,
            (new FulfilledPromise(1))->await()
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
        $promise = new Promise(function () {
            throw new \OutOfBoundsException('foo');
        });
        try {
            $promise->await();
            $this->fail('We did not get the expected exception');
        } catch (\Exception $e) {
            $this->assertInstanceOf('OutOfBoundsException', $e);
            $this->assertEquals('foo', $e->getMessage());
        }
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage foo
     */
    public function testWaitRejectedScalar()
    {
        $promise = (new Promise(function () {
            throw new Exception('foo');
        }))->await();
    }

    public function testCancelPending()
    {
        $promise = new Promise();
        $promise->cancel();

        $promise->then(function () {
            throw new \RuntimeException('Should not throw');
        });
        $this->assertTrue($promise->isCanceled());
    }
}
