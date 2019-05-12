<?php
namespace Promise\Tests;

use Onion\Framework\Promise\AwaitablePromise;
use Onion\Framework\Promise\Promise;
use PHPUnit\Framework\TestCase;

class AwaitablePromiseTest extends TestCase
{
    public function testAwaitOnFulfilled()
    {
        $this->expectOutputString('');
        $value = (new AwaitablePromise(function ($resolve) {
            $resolve(1);
        }, function () {
            echo 'wait';
        }));

        $this->assertSame(1, $value->await());
    }

    public function testAwaitOnRejected()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('1');
        $this->expectOutputString('');

        $value = (new AwaitablePromise(function ($resolve, $reject) {
            $reject(new \Exception('1'));
        }, function () {
            echo 'wait';
        }));

        $value->await();
    }

    public function testAwaitOnPending()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectOutputString('foo');
        (new AwaitablePromise(function () {}, function () {
            echo 'foo';
        }))->await();
    }

    public function testAwaitOnCanceled()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectOutputString('foo');
        $promise = (new AwaitablePromise(function () {}, function () {}, function () {
            echo 'foo';
        }));
        $promise->cancel();
        $promise->await();
    }

    public function testWaitingOnPromiseOfAPromise()
    {
        $this->expectOutputString('wait');
        $promise = new AwaitablePromise(function ($resolve) {
            $r = null;
            $resolve(new AwaitablePromise(function ($resolve) use (&$r) {
                $r = $resolve;
            }, function () use (&$r) {
                $r(1);
                echo 'wait';
            }));
        }, function () {});

        $promise->then(function ($v) {
            $this->assertSame(1, $v);
        })->await();
        $this->assertTrue($promise->isFulfilled());
    }
}
