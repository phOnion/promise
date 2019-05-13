<?php
namespace Onion\Framework\Promise;

use function Onion\Framework\EventLoop\coroutine;
use function Onion\Framework\EventLoop\loop;
use Onion\Framework\Promise\CancelablePromise;
use Onion\Framework\Promise\Interfaces\CancelableInterface;
use Onion\Framework\Promise\Interfaces\ThenableInterface;

if (!function_exists(__NAMESPACE__ . '\is_thenable')) {
    function is_thenable($value): bool
    {
        return (
            is_object($value) && method_exists($value, 'then')
        ) || $value instanceof ThenableInterface;
    }
}

if (!function_exists(__NAMESPACE__ . '\async')) {
    function async(callable $callback, ?callable $waitFn = null, ?callable $closeFn = null, ...$params)
    {
        return new AwaitablePromise(function ($resolve, $reject) use ($callback, $params) {
            coroutine(function ($callback, $resolve, $reject) use ($params) {
                try {
                    $resolve(call_user_func($callback, ...$params));
                } catch (\Throwable $ex) {
                    $reject($ex);
                }
            }, $callback, $resolve, $reject);
        }, function () use ($waitFn) {
            call_user_func($waitFn ?? [loop(), 'tick']);
        }, $closeFn ?? function () {
        });
    }
}

if (!function_exists(__NAMESPACE__ . '\promise')) {
    function promise(callable $task, ?callable $cancel = null, ...$params): CancelableInterface
    {
        return new CancelablePromise(function ($resolve, $reject) use ($task, $params) {
            coroutine(function (callable $task, array $params) use ($resolve, $reject) {
                try {
                    $resolve(call_user_func($task, ...$params));
                } catch (\Throwable $ex) {
                    $reject($ex);
                }
            }, $task, $params);
        }, $cancel ?? function () {
        });
    }
}
