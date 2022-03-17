<?php

namespace Promise\Tests;

use Onion\Framework\Promise\AwaitablePromise;
use Onion\Framework\Test\TestCase;

use function Onion\Framework\Promise\await;

class AwaitablePromiseTest extends TestCase
{
    public function testAwaitOnFulfilled()
    {
        $this->expectOutputString('');
        $this->assertSame(1, await(new AwaitablePromise(function ($resolve) {
            $resolve(1);
        }, function () {
            echo 'wait';
        })));
    }

    public function testAwaitOnRejected()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('1');
        $this->expectOutputString('');

        (new AwaitablePromise(function ($resolve, $reject) {
            $reject(new \Exception('1'));
        }))->await();
    }

    public function testWaitingOnPromiseOfAPromise()
    {
        $promise = AwaitablePromise::resolve(
            new AwaitablePromise(fn ($resolve) => $resolve(1))
        );

        $this->assertSame(1, $promise->await());
    }
}
