<?php
declare(strict_types=1);

namespace Promise\Tests;

use Exception;
use Onion\Framework\Promise\CancelablePromise;
use Onion\Framework\Promise\FulfilledPromise;
use Onion\Framework\Promise\Promise;
use Onion\Framework\Promise\RejectedPromise;

class PromiseTest extends \PHPUnit\Framework\TestCase
{
    public function testSuccess()
    {
        $promise = (new FulfilledPromise(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            $this->assertEquals(3, $value);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testFail()
    {
        $promise = (new RejectedPromise(new Exception('1')))
            ->then(null, function (\Throwable $value) {
                $this->assertInstanceOf(\Exception::class, $value);
                $this->assertSame('1', $value->getMessage());
            });
        $this->assertTrue($promise->isRejected());
    }

    public function testChain()
    {
        $promise = (new FulfilledPromise(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            return $value + 4;
        })->then(function ($value) {
            $this->assertEquals(7, $value);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testChainPromise()
    {
        $promise = (new FulfilledPromise(1))->then(function ($value) {
            return new FulfilledPromise(2);
        })->then(function ($value) {
            return ($value + 4);
        })->then(function ($value) {
            $this->assertEquals(6, $value);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testRejectedChainPromise()
    {
        $promise = (new FulfilledPromise(1))->then(function ($value) {
            return new RejectedPromise(new \RuntimeException('2'));
        })->otherwise(function ($reason) {
            return ($reason->getMessage() + 4);
        })->then(function ($value) {
            $this->assertEquals(6, $value);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testRejectedChainPromiseWithThrow()
    {
        $promise = (new FulfilledPromise(1))->then(function ($value) {
            return new RejectedPromise(new \RuntimeException('2'));
        })->otherwise(function ($reason) {
            throw new \Exception('3');
        })->otherwise(function (\Throwable $ex) {
            $this->assertSame('3', $ex->getMessage());
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testChainCallback()
    {
        $promise = (new FulfilledPromise(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value)  {
            return function ($resolve, $reject) use ($value) {
                $resolve($value += 3);
            };
        })->then(function ($value) {
            $this->assertSame(6, $value);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testChainThenable()
    {
        $promise = (new FulfilledPromise(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            return new class($value) {
                private $finalValue;
                public function __construct(&$finalValue) { $this->finalValue = &$finalValue; }
                public function then($resolve, $reject) {
                    $resolve($this->finalValue + 2);
                }
            };
        })->then(function ($value) {
            $this->assertEquals(5, $value);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testPendingResult()
    {
        $promise = (new FulfilledPromise(4))
            ->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            $this->assertEquals(6, $value);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testExecutorSuccess()
    {
        $promise = (new Promise(function ($success, $fail) {
            $success('hi');
        }))->then(function ($result) {
            return $result;
        })->then(function ($value) {
            $this->assertEquals('hi', $value);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testExecutorFail()
    {
        $promise = (new Promise(function ($success, $fail) {
            $fail(new Exception('hi'));
        }))->then(function ($result) {
            return 'incorrect';
        })->otherwise(function ($reason) {
            return $reason;
        })->otherwise(function ($reason) {
            $this->assertSame('hi', $reason->getMessage());
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testRejectTwice()
    {
        $promise = (new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $reject(new Exception('2'));
        }))->otherwise(function () {
            throw new Exception('2');
        })->otherwise(function ($reason) {
            $this->assertSame('2', $reason->getMessage());
        });

        $this->assertTrue($promise->isRejected());
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
        $this->assertTrue($promise->isRejected());
    }

    public function testFulfillRejectedException()
    {
        $promise = (new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $resolve(true);
        }))->otherwise(function ($exception) {
            $this->assertInstanceOf(\LogicException::class, $exception);
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testFulfillRejected()
    {
        $promise = (new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $resolve(true);
        }))->otherwise(function ($reason) {
            return true;
        })->then(function ($value) {
            $this->assertTrue($value);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testSelfResolution()
    {
        $promise = new FulfilledPromise(true);
        $promise->then(function ($reason) use (&$promise) {
            return $promise;
        })->otherwise(function ($reason) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $reason);
            $this->assertSame('Unable to process promise with itself', $reason->getMessage());
        })->then(function () {
            $this->fail('exception not thrown');
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testWaitRejectedException()
    {
        $promise = (new Promise(function () {
            throw new \OutOfBoundsException('foo');
        }))->then(function () {
            $this->fail('We did not get the expected exception');
        }, function ($e) {
            $this->assertInstanceOf('OutOfBoundsException', $e);
            $this->assertEquals('foo', $e->getMessage());
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testFinallyCalls()
    {
        $t = false;
        $promise = (new FulfilledPromise(1))
            ->finally(function() {}, function () use (&$t) {
                $t = true;
            });

        $this->assertTrue($promise->isFulfilled());
        $this->assertTrue($t);
    }


    public function testFulfilledWithClosure()
    {
        $promise = (new RejectedPromise(new \Exception('1')))
            ->otherwise(function () {
                return function($resolve, $reject) {
                    $resolve(3);
                };
            })->then(function ($value) {
                $this->assertSame(3, $value);
            });

        $this->assertTrue($promise->isFulfilled());
    }


    public function testRejectFulfilledFromClosure()
    {
        $promise = (new FulfilledPromise(1))->then(function () {
            return function($resolve, $reject) {
                $reject(new \Exception('1'));
            };
        })->otherwise(function ($reason) {
            $this->assertSame('1', $reason->getMessage());
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testRejectFulfilledFromThen()
    {
        $promise = (new FulfilledPromise(1))->then(function () {
            return function($resolve, $reject) {
                $reject(new \Exception('1'));
            };
        }, function () {
            throw new \Exception('2');
        })->otherwise(function ($ex) {
            $this->assertSame('2', $ex->getMessage());
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testResolveHandledRejectionPromise()
    {
        $promise = (new RejectedPromise(new \Exception('1')))
            ->then(null, function () {
                return true;
            })->then(function ($value) {
                $this->assertTrue($value);
            });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testDisallowedStateChange()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Promise already fulfilled');

        $promise = (new Promise(function ($resolve, $reject) {
            $resolve(1);
            $reject(new \Exception('1'));
        }));

        $this->assertTrue($promise->isFulfilled());
    }

    public function testPromiseResolutionWithPendingPromise()
    {
        $promise = (new Promise(function ($resolve, $reject) {
            $resolve(new Promise(function ($resolve) {
                $resolve(1);
            }));
        }))->then(function ($result) {
            $this->assertSame(1, $result);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testPromiseResolutionWithResolvedPromise()
    {
        $promise = (new Promise(function ($resolve) {
            $resolve(new FulfilledPromise(1));
        }))->then(function ($result) {
            $this->assertSame(1, $result);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testPromiseResolutionWithRejectedPromise()
    {
        $promise = (new Promise(function ($resolve) {
            $resolve(new RejectedPromise(new \Exception('1')));
        }))->then(function ($result) {
            var_dump($result);
        })->otherwise(function ($result) {
            $this->assertSame('1', $result->getMessage());
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testRejectionOfFulfilledPromise()
    {
        $promise = (new Promise(function ($resolve) {
            $resolve(1);
        }))->then(function () {
            return 2;
        })->then(function () {
            throw new \RuntimeException('foo');
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testPromiseResolutionFromThenable()
    {
        $promise = (new FulfilledPromise(1))
            ->then(function () {
                return new class {
                    public function then($resolve) {
                        $resolve(2);
                    }
                };
            })->then(function ($result) {
                $this->assertSame(2, $result);
            });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testPromiseRejectionFromThenable()
    {
        $promise = (new FulfilledPromise(1))
            ->then(function () {
                return new class {
                    public function then($resolve, $reject) {
                        $reject(new \Exception('5'));
                    }
                };
            })->otherwise(function ($reason) {
                $this->assertSame('5', $reason->getMessage());
            })->then(function () {
                var_dump('noe');
                $this->fail('Nope');
            });

        $this->assertTrue($promise->isRejected());
    }

    public function testStaticRace()
    {
        $stack = [
            new Promise(),
            new Promise(),
            new Promise(),
            new FulfilledPromise(1),
            new FulfilledPromise(2),
            new FulfilledPromise(3)
        ];

        $promise = Promise::race($stack)->then(function ($value) {
            $this->assertSame(1, $value);
        });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testRejectionStaticRace()
    {
        $stack = [
            new Promise(),
            new Promise(),
            new Promise(),
            new Promise(),
            new Promise(),
            new RejectedPromise(new \Exception('1'))
        ];

        $promise = Promise::race($stack)->otherwise(function ($reason) {
            $this->assertSame('1', $reason->getMessage());
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testStaticAll()
    {
        $stack = [
            new FulfilledPromise(1),
            new FulfilledPromise(2),
            new FulfilledPromise(3),
            new FulfilledPromise(4),
            new FulfilledPromise(5),
            new FulfilledPromise(6),
        ];

        foreach ($stack as $index => $item) {
            $expected[$index] = $index+1;
        }

        $promise = Promise::all($stack)
            ->then(function ($all) use ($expected) {
                $this->assertSame($expected, $all);
            });

        $this->assertTrue($promise->isFulfilled());
    }

    public function testRejectedStaticAll()
    {
        $stack = [
            new FulfilledPromise(1),
            new FulfilledPromise(1),
            new FulfilledPromise(1),
            new FulfilledPromise(1),
            new FulfilledPromise(1),
            new RejectedPromise(new \Exception('1'))
        ];

        $promise = Promise::all($stack)->otherwise(function ($reason) {
            $this->assertSame('1', $reason->getMessage());
        });

        $this->assertTrue($promise->isRejected());
    }

    public function testResolvedWithCanceledPromise()
    {
        $promise = (new Promise(function ($resolve) {
            $p = new CancelablePromise(function () {}, function () {});
            $p->cancel();

            $resolve($p);
        }));

        $this->assertFalse($promise->isPending());
        $this->assertFalse($promise->isFulfilled());
        $this->assertFalse($promise->isRejected());
    }

    public function testFinally()
    {
        $this->expectOutputString('foobar');
        $c = null;
        (new Promise(function ($resolve) use (&$c) {
            $c = new class($resolve) {
                private $r;

                public function __construct(callable $resolve)
                {
                    $this->r = $resolve;
                }

                public function test()
                {
                    call_user_func($this->r, 1);
                }
            };
        }))->then(function () { echo 'foo'; })
            ->finally(function () { echo 'bar'; });

        $c->test();
    }
}
