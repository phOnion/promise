<?php
namespace Promise\Tests;

use Onion\Framework\Promise\CancelablePromise;
use Onion\Framework\Promise\Promise;
use PHPUnit\Framework\TestCase;

class CancelablePromiseTest extends TestCase
{
    public function testProperState()
    {
        $this->expectOutputString('Cancel');
        $promise = new CancelablePromise(function () {}, function () {
            echo 'Cancel';
        });
        $this->assertTrue($promise->isPending());
        $promise->cancel();

        $this->assertTrue($promise->isCanceled());
    }

    public function testCancelOfFulfilled()
    {
        $this->expectOutputString('Cancel');
        $promise = new CancelablePromise(function ($resolve) {
            $resolve(1);
        }, function () {
            echo 'Cancel';
        });

        $promise->cancel();
        $this->assertTrue($promise->isCanceled());
    }

    public function testCancelOfRejected()
    {
        $this->expectOutputString('Cancel');
        $promise = new CancelablePromise(function ($resolve, $reject) {
            $reject(new Exception('ex'));
        }, function () {
            echo 'Cancel';
        });

        $promise->cancel();
        $this->assertTrue($promise->isCanceled());
    }

    public function testHaltAfterCancel()
    {
        $this->expectOutputString('bar');
        $promise = new CancelablePromise(function ($resolve) {
            $resolve(1);
        }, function () {
            echo 'bar';
        });

        $promise->cancel();
        $promise->then(function () {
            echo "foo";
        });
        $this->assertTrue($promise->isCanceled());
    }
}

