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
        (new FulfilledPromise(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            $this->assertEquals(3, $value);
        });
    }


    public function testFail()
    {
        (new RejectedPromise(new Exception('1')))
            ->then(null, function (\Throwable $value) {
                $this->assertInstanceOf(\Exception::class, $value);
                $this->assertSame('1', $value->getMessage());
            });

    }

    public function testChain()
    {
        (new FulfilledPromise(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            return $value + 4;
        })->then(function ($value) {
            $this->assertEquals(7, $value);
        });
    }

    public function testChainPromise()
    {
        (new FulfilledPromise(1))->then(function ($value) {
            return new FulfilledPromise(2);
        })->then(function ($value) {
            return ($value + 4);
        })->then(function ($value) {
            $this->assertEquals(6, $value);
        });
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
    }

    public function testChainCallback()
    {
        $finalValue = 0;

        (new FulfilledPromise(1))->then(function ($value) {
            return $value + 2;
        })->then(function ($value)  {
            return function ($resolve, $reject) use ($value) {
                $resolve($value += 3);
            };
        })->then(function ($value) {
            $this->assertSame(6, $value);
        });
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
    }

    public function testPendingResult()
    {
        $promise = (new FulfilledPromise(4))
            ->then(function ($value) {
            return $value + 2;
        })->then(function ($value) {
            $this->assertEquals(6, $value);
        });
    }

    public function testExecutorSuccess()
    {
        (new Promise(function ($success, $fail) {
            $success('hi');
        }))->then(function ($result) {
            return $result;
        })->then(function ($value) {
            $this->assertEquals('hi', $value);
        });
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
        (new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $reject(new Exception('2'));
        }))->otherwise(function () {
            throw new Exception('2');
        })->otherwise(function ($reason) {
            $this->assertSame('2', $reason->getMessage());
        });
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
        (new FulfilledPromise(1))
            ->then(function ($value) {
                $this->assertSame(1, $value);
            });
    }

    public function testFulfillRejectedException()
    {
        (new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $resolve(true);
        }))->otherwise(function ($exception) {
            $this->assertInstanceOf(\LogicException::class, $exception);
        });
    }

    public function testFulfillRejected()
    {
        (new Promise(function ($resolve, $reject) {
            $reject(new Exception('1'));
            $resolve(true);
        }))->otherwise(function ($reason) {
            return true;
        })->then(function ($value) {
            $this->assertTrue($value);
        });
    }

    public function testSelfResolution()
    {
        $promise = new FulfilledPromise(true);
        $promise->then(function ($reason) use (&$promise) {
            return $promise;
        })->otherwise(function ($reason) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $reason);
        });
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
    }

    public function testFinallyCalls()
    {
        $t = false;
        $promise = (new FulfilledPromise(1))
            ->finally(function() {}, function () use (&$t) {
                $t = true;
            });

        $this->assertTrue($t);
    }


    public function testFulfilledWithClosure()
    {
        (new FulfilledPromise(1))->then(function () {
            return function($resolve, $reject) {
                $resolve(3);
            };
        })->then(function ($value) {
            $this->assertSame(3, $value);
        });
    }


    public function testRejectFulfilledFromClosure()
    {
        (new FulfilledPromise(1))->then(function () {
            return function($resolve, $reject) {
                $reject(new \Exception('1'));
            };
        })->otherwise(function ($reason) {
            $this->assertSame('1', $reason->getMessage());
        });
    }

    public function testRejectFulfilledFromThen()
    {
        (new FulfilledPromise(1))->then(function () {
            return function($resolve, $reject) {
                $reject(new \Exception('1'));
            };
        }, function () {
            throw new \Exception('2');
        })->otherwise(function ($ex) {
            $this->assertSame('2', $ex->getMessage());
        });
    }

    public function testResolveHandledRejectionPromise()
    {
        (new RejectedPromise(new \Exception('1')))
            ->then(null, function () {
                return true;
            })->then(function ($value) {
                $this->assertTrue($value);
            });
    }

    public function testStaticRace()
    {
        for ($i=0; $i<5; $i++) {
            $stack = [
                new Promise(),
                new Promise(),
                new Promise(),
                new FulfilledPromise($i),
                new FulfilledPromise($i+2),
                new FulfilledPromise($i+1)
            ];

            Promise::race($stack)->then(function ($value) use ($i) {
                $this->assertSame($i, $value);
            });
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

            Promise::race($stack)->otherwise(function ($reason) use ($i) {
                $this->assertSame("{$i}", $reason->getMessage());
            });
        }
    }

    public function testStaticAll()
    {
        for ($i=0; $i<5; $i++) {
            $values = [];
            $expected = [];
            for ($j=0; $j<6; $j++) {
                $values[$j] = mt_rand(0, 10);
            }
            $stack = [
                mt_rand(0, 100) => new FulfilledPromise($values[0]),
                mt_rand(0, 100) => new FulfilledPromise($values[1]),
                mt_rand(0, 100) => new FulfilledPromise($values[2]),
                mt_rand(0, 100) => new FulfilledPromise($values[3]),
                mt_rand(0, 100) => new FulfilledPromise($values[4]),
                mt_rand(0, 100) => new FulfilledPromise($values[5]),
            ];


            $c = 0;
            foreach ($stack as $index => $item) {
                $expected[$index] = $values[$c];
                $c++;
            }

            ksort($expected);

            Promise::all($stack)->then(function ($all) use ($expected) {
                $this->assertSame($expected, $all);
            });
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

            Promise::all($stack)->otherwise(function ($reason) use ($i) {
                $this->assertSame("{$i}", $reason->getMessage());
            });
        }
    }
}
