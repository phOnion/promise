<?php
namespace Onion\Framework\Promise;

use Closure;
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
    function async(Closure $callback, Closure $closeFn = null)
    {
        return new Promise(function ($resolve, $reject) use ($callback) {
            coroutine(function () use ($callback, $resolve, $reject) {
                try {
                    $resolve($callback());
                } catch (\Throwable $ex) {
                    $reject($ex);
                }
            });

            loop()->tick();
        }, function () {
            loop()->tick();
        }, $closeFn);
    }
}
