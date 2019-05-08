<?php
namespace Onion\Framework\Promise;

use function Onion\Framework\EventLoop\coroutine;
use function Onion\Framework\EventLoop\loop;
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
    function async(callable $callback, callable $closeFn = null, ...$params)
    {
        return new AwaitablePromise(function ($resolve, $reject) use ($callback, $params) {
            coroutine(function ($callback, $resolve, $reject) use ($params) {
                try {
                    $resolve($callback(...$params));
                } catch (\Throwable $ex) {
                    $reject($ex);
                }
            }, $callback, $resolve, $reject);
        }, function () {
            loop()->tick();
        }, $closeFn ?? function () {});
    }
}
