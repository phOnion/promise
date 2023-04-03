<?php

declare(strict_types=1);

namespace Promise\Tests;

use Exception;
use Onion\Framework\Promise\CancelablePromise;
use Onion\Framework\Promise\Promise;
use Onion\Framework\Test\TestCase;
use RuntimeException;

use function Onion\Framework\Promise\await;

class PromiseTest extends TestCase
{
    public function testSuccess()
    {
        await((Promise::resolve(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            $this->assertEquals(3, $value);

            // return
        }));
    }

    public function testFail()
    {
        await((Promise::reject(new Exception('1')))
            ->then(null, function (\Throwable $value) {
                $this->assertInstanceOf(\Exception::class, $value);
                $this->assertSame('1', $value->getMessage());
            }));
    }

    public function testChain()
    {
        await((Promise::resolve(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            return $value + 4;
        })->then(function ($value) {
            $this->assertEquals(7, $value);
        }));
    }

    public function testChainPromise()
    {
        await((Promise::resolve(1))->then(function ($value) {
            return Promise::resolve(2);
        })->then(function ($value) {
            return ($value + 4);
        })->then(function ($value) {
            $this->assertEquals(6, $value);
        }));
    }

    public function testRejectedChainPromise()
    {
        await((Promise::resolve(1))
            ->then(function ($value) {
                return Promise::reject(new \RuntimeException('2'));
            })->catch(function ($reason) {
                return ($reason->getMessage() + 4);
            })->then(function ($value) {
                $this->assertEquals(6, $value);
            }));
    }

    public function testRejectedChainPromiseWithThrow()
    {
        await((Promise::resolve(1))
            ->then(function ($value) {
                return Promise::reject(new \RuntimeException('2'));
            })->catch(function ($reason) {
                throw new \Exception('3');
            })->catch(function (\Throwable $ex) {
                $this->assertSame('3', $ex->getMessage());
            }));
    }

    public function testChainCallback()
    {
        await((Promise::resolve(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            return new Promise(function ($resolve, $reject) use ($value) {
                $resolve($value += 3);
            });
        })->then(function ($value) {
            $this->assertSame(6, $value);
        }));
    }

    public function testChainThenable()
    {
        await((Promise::resolve(1))
            ->then(function ($value) {
                return $value + 2;
            })->then(function ($value) {
                return new class($value)
                {
                    private $finalValue;
                    public function __construct(&$finalValue)
                    {
                        $this->finalValue = &$finalValue;
                    }
                    public function then($resolve, $reject)
                    {
                        $resolve($this->finalValue + 2);
                    }
                };
            })->then(function ($value) {
                $this->assertEquals(5, $value);
            }));
    }

    public function testPendingResult()
    {
        await((Promise::resolve(4))
            ->then(function ($value) {
                return $value + 2;
            })->then(function ($value) {
                $this->assertEquals(6, $value);
            }));
    }

    public function testExecutorSuccess()
    {
        await((new Promise(function ($success, $fail) {
            $success('hi');
        }))->then(function ($result) {
            return $result;
        })->then(function ($value) {
            $this->assertEquals('hi', $value);
        }));
    }

    public function testExecutorFail()
    {
        await((new Promise(function ($success, $fail) {
            $fail(new Exception('hi'));
        }))->then(function ($result) {
            return 'incorrect';
        })->catch(function ($reason) {
            return $reason;
        })->then(function ($reason) {
            $this->assertSame('hi', $reason->getMessage());
        }));
    }

    public function testRejectTwice()
    {
        $promise = (new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $reject(new Exception('2'));
        }))->catch(function () {
            throw new Exception('2');
        })->catch(function ($reason) {
            $this->assertSame('2', $reason->getMessage());
        });
    }

    public function testFromFailureHandler()
    {
        $ok = 0;
        $promise = Promise::reject(new Exception('foo'));
        await($promise->catch(function ($reason) {
            $this->assertEquals('foo', $reason->getMessage());
            throw new \Exception('hi');
        })->then(function () use (&$ok) {
            $ok = -1;
        }, function () use (&$ok) {
            $ok = 1;
        }));

        $this->assertEquals(1, $ok);
    }

    public function testFulfillRejectedException()
    {
        $promise = (new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $resolve(true);
        }))->catch(function ($exception) {
            $this->assertInstanceOf(\LogicException::class, $exception);
        });
    }

    public function testFulfillRejected()
    {
        await((new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $resolve(true);
        }))->catch(function ($reason) {
            return true;
        })->then(function ($value) {
            $this->assertTrue($value);
        }));
    }

    public function testSelfResolution()
    {
        $promise = Promise::resolve(true);
        await($promise->then(function ($reason) use (&$promise) {
            return $promise;
        })->catch(function ($reason) {
            $this->assertInstanceOf(\LogicException::class, $reason);
            $this->assertSame('Unable to resolve promise with itself', $reason->getMessage());
        }));
    }

    public function testWaitRejectedException()
    {
        await((new Promise(function () {
            throw new \OutOfBoundsException('foo');
        }))->then(function () {
            $this->fail('We did not get the expected exception');
        }, function ($e) {
            $this->assertInstanceOf('OutOfBoundsException', $e);
            $this->assertEquals('foo', $e->getMessage());
        }));
    }

    public function testFinallyCalls()
    {
        $t = false;
        await((Promise::resolve(1))
            ->finally(function () use (&$t) {
                $t = true;
            }));

        $this->assertTrue($t);
    }


    public function testFulfilledWithClosure()
    {
        $promise = (Promise::reject(new \Exception('1')))
            ->catch(function () {
                return function ($resolve, $reject) {
                    $resolve(3);
                };
            })->then(function ($value) {
                $this->assertSame(3, $value);
            });
    }

    public function testRejectFulfilledFromThen()
    {
        await((Promise::resolve(1))
            ->then(function () {
                throw new \Exception('1');
            }, function () {
                throw new \Exception('2');
            })->catch(function ($ex) {
                $this->assertSame('2', $ex->getMessage());
            }));
    }

    public function testResolveHandledRejectionPromise()
    {
        await((Promise::reject(new \Exception('1')))
            ->then(null, function () {
                return true;
            })->then(function ($value) {
                $this->assertTrue($value);
            }));
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
    }

    public function testPromiseResolutionWithResolvedPromise()
    {
        $promise = (new Promise(function ($resolve) {
            $resolve(Promise::resolve(1));
        }))->then(function ($result) {
            $this->assertSame(1, $result);
        });
    }

    public function testPromiseResolutionWithFulfilledPromise()
    {
        await((new Promise(function ($resolve) {
            $resolve(
                Promise::reject(new \Exception('1'))
            );
        }))->then(function ($result) {
            var_dump($result);
        })->catch(function ($result) {
            $this->assertSame('1', $result->getMessage());
        }));
    }

    public function testRejectionOfFulfilledPromise()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('foo');
        await((new Promise(function ($resolve) {
            $resolve(1);
        }))->then(function () {
            return 2;
        })->then(function () {
            throw new \RuntimeException('foo');
        }));
    }

    public function testPromiseResolutionFromThenable()
    {
        await((Promise::resolve(1))
            ->then(function () {
                return new class
                {
                    public function then($resolve)
                    {
                        $resolve(2);
                    }
                };
            })->then(function ($result) {
                $this->assertSame(2, $result);
            }));
    }

    public function testPromiseRejectionFromThenable()
    {
        await((Promise::resolve(1))
            ->then(function () {
                return new class
                {
                    public function then($resolve, $reject)
                    {
                        $reject(new \Exception('5'));
                    }
                };
            })->catch(function ($reason) {
                $this->assertSame('5', $reason->getMessage());
            })->then(function ($v) {
                $this->assertNull($v);
            }));
    }

    public function testStaticRace()
    {
        $stack = [
            new Promise(fn () => null),
            new Promise(fn () => null),
            new Promise(fn () => null),
            Promise::resolve(1),
            Promise::resolve(2),
            Promise::resolve(3)
        ];

        $this->assertSame(1, await(Promise::race(...$stack)));
    }

    public function testRejectionStaticRace()
    {
        $stack = [
            new Promise(fn () => null),
            new Promise(fn () => null),
            new Promise(fn () => null),
            new Promise(fn () => null),
            new Promise(fn () => null),
            Promise::reject(new \Exception('1')),
            Promise::reject(new \Exception('2')),
        ];


        $this->expectException(Exception::class);
        $this->expectExceptionMessage('1');

        await(Promise::race(...$stack));
    }

    public function testStaticAll()
    {
        $stack = [
            Promise::resolve(1),
            Promise::resolve(2),
            Promise::resolve(3),
            Promise::resolve(4),
            Promise::resolve(5),
            Promise::resolve(6),
        ];

        foreach ($stack as $index => $item) {
            $expected[$index] = $index + 1;
        }

        $this->assertSame($expected, await(Promise::all(...$stack)));
    }

    public function testRejectedStaticAll()
    {
        $stack = [
            Promise::resolve(1),
            Promise::resolve(1),
            Promise::resolve(1),
            Promise::resolve(1),
            Promise::resolve(1),
            Promise::reject(new \Exception('1'))
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('1');
        await(Promise::all(...$stack));

        // $this->assertTrue($promise->isRejected());
    }

    // public function testResolvedWithCanceledPromise()
    // {
    //     $promise = (new Promise(function ($resolve) {
    //         $p = new CancelablePromise(function () {
    //         }, function () {
    //         });
    //         $p->cancel();

    //         $resolve($p);
    //     }));

    //     $this->assertFalse($promise->isPending());
    //     $this->assertFalse($promise->isFulfilled());
    //     $this->assertFalse($promise->isRejected());
    // }

    // public function testFinally()
    // {
    //     $this->expectOutputString('foobar');
    //     /** @var Promise $c */
    //     $c = null;
    //     (new Promise(function ($resolve) use (&$c) {
    //         $c = new class($resolve)
    //         {
    //             private $r;

    //             public function __construct(callable $resolve)
    //             {
    //                 $this->r = $resolve;
    //             }

    //             public function test()
    //             {
    //                 call_user_func($this->r, 1);
    //             }
    //         };
    //     }))->then(function () {
    //         echo 'foo';
    //     })
    //         ->finally(function () {
    //             echo 'bar';
    //         });
    // }
}
