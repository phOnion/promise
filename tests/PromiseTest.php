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

    public function testRejectedChainPromise()
    {
        $promise = (new FulfilledPromise(1))->then(function ($value) {
            return new RejectedPromise(new \RuntimeException('2'));
        })->otherwise(function ($reason) {
            return ($reason->getMessage() + 4);
        });

        $this->assertEquals(6, $promise->await());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage 3
     */
    public function testRejectedChainPromiseWithThrow()
    {
        $promise = (new FulfilledPromise(1))->then(function ($value) {
            return new RejectedPromise(new \RuntimeException('2'));
        })->otherwise(function ($reason) {
            throw new \Exception('3');
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
        $promise = (new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $reject(new Exception('2'));
        }))->otherwise(function () {
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
     * @expectedExceptionMessage Promise already rejected
     */
    public function testFulfillRejected()
    {
        $promise = (new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $resolve(true);
        }))->otherwise(function ($reason) {
            return $reason;
        })->await();
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Unable to process promise with itself
     */
    public function testSelfResolution()
    {
        $promise = new FulfilledPromise(true);
        $promise->then(function ($reason) use (&$promise) {
            return $promise;
        })->await();
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

    public function testFinallyCalls()
    {
        $promise = (new FulfilledPromise(1))
            ->finally(function () {
                $this->assertTrue(true);
            })
            ->then(function () {
                $this->assertFalse(false);
            });
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

    public function testFulfilledWithClosure()
    {
        $this->assertSame(3, (new FulfilledPromise(1))->then(function () {
            return function($resolve, $reject) {
                $resolve(3);
            };
        })->await());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage 1
     */
    public function testRejectFulfilledFromClosure()
    {
        $this->assertSame(3, (new FulfilledPromise(1))->then(function () {
            return function($resolve, $reject) {
                $reject(new \Exception('1'));
            };
        })->await());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage 2
     */
    public function testRejectFulfilledFromThen()
    {
        $this->assertSame(3, (new FulfilledPromise(1))->then(function () {
            return function($resolve, $reject) {
                $reject(new \Exception('1'));
            };
        }, function () {
            throw new \Exception('2');
        })->await());
    }

    public function testResolveHandledRejectionPromise()
    {
        $this->assertTrue((new RejectedPromise(new \Exception('1')))
            ->then(null, function () {
                return true;
            })->await());
    }

    public function testStaticRace()
    {
        for ($i=0; $i<5; $i++) {
            $stack = [
                new Promise(),
                new Promise(),
                new Promise(),
                new Promise(),
                new Promise(),
                new FulfilledPromise($i)
            ];

            shuffle($stack);

            $this->assertSame($i, Promise::race($stack)->await());
        }
    }

    public function testRejectionStaticRace()
    {
        for ($i=0; $i<5; $i++) {
            $stack = [
                new Promise(),
                new Promise(),
                new Promise(),
                new Promise(),
                new Promise(),
                new RejectedPromise(new \Exception("{$i}"))
            ];

            shuffle($stack);

            try {
                Promise::race($stack)->await();
            } catch (\Exception $ex) {
                $this->assertSame("{$i}", $ex->getMessage());
            }
        }
    }

    public function testStaticAll()
    {
        for ($i=0; $i<5; $i++) {
            $stack = [
                new FulfilledPromise(mt_rand(0, 10)),
                new FulfilledPromise(mt_rand(0, 10)),
                new FulfilledPromise(mt_rand(0, 10)),
                new FulfilledPromise(mt_rand(0, 10)),
                new FulfilledPromise(mt_rand(0, 10)),
                new FulfilledPromise(mt_rand(0, 10))
            ];

            $expected = [];
            foreach ($stack as $index => $item) {
                $expected[$index] = $item->await();
            }

            $this->assertSame($expected, Promise::all($stack)->await());
        }
    }

    public function testRejectedStaticAll()
    {
        for ($i=0; $i<5; $i++) {
            $stack = [
                new FulfilledPromise(1),
                new FulfilledPromise(1),
                new FulfilledPromise(1),
                new FulfilledPromise(1),
                new FulfilledPromise(1),
                new RejectedPromise(new \Exception("{$i}"))
            ];

            shuffle($stack);

            try {
                Promise::all($stack)->await();
            } catch (\Exception $ex) {
                $this->assertSame("{$i}", $ex->getMessage());
            }
        }
    }
}
