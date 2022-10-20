<?php

namespace Promise\Tests;

use Onion\Framework\Promise\Deferred;
use Onion\Framework\Promise\Interfaces\PromiseInterface;
use Onion\Framework\Test\TestCase;
use RuntimeException;

use function Onion\Framework\Loop\coroutine;

class DeferredTest extends TestCase
{
    public function testPromise()
    {
        $this->assertInstanceOf(PromiseInterface::class, (new Deferred)->promise());
    }
    public function testResolution()
    {
        $deferred = new Deferred();

        $deferred->promise()
            ->then(function ($v) {
                $this->assertTrue($v);
            });

        $deferred->resolve(true);
    }

    public function testRejection()
    {
        $deferred = new Deferred();

        $deferred->promise()
            ->catch(function (\Throwable $ex) {
                $this->assertInstanceOf(RuntimeException::class, $ex);
                $this->assertSame('foo', $ex->getMessage());
            });

        $deferred->reject(new RuntimeException('foo'));
    }

    public function testCompletionExceptionOnResolve()
    {
        $this->expectException(RuntimeException::class);
        $deferred = new Deferred();

        $deferred->resolve(null);
        $deferred->resolve(null);
    }

    public function testCompletionExceptionOnReject()
    {
        $this->expectException(RuntimeException::class);
        $deferred = new Deferred();

        $deferred->reject(new \Exception('foo'));
        $deferred->reject(new \Exception('foo'));
    }

    public function testCompletionFromResolution()
    {
        $deferred = new Deferred();
        $deferred->promise()->then($this->assertTrue(...));

        coroutine(function (Deferred $d) {
            coroutine(fn () => coroutine(fn () => $d->resolve(true)));
        }, [$deferred]);
    }

    public function testCompletionFromRejection()
    {
        $deferred = new Deferred();
        $deferred->promise()->catch(fn ($ex) => $this->assertInstanceOf(RuntimeException::class, $ex));

        coroutine(function (Deferred $d) {
            coroutine(fn () => coroutine(fn () => $d->reject(new RuntimeException('foo'))));
        }, [$deferred]);
    }
}
