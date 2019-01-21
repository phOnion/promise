<?php
namespace Onion\Framework\Promise;

use Closure;
use Onion\Framework\Promise\Interfaces\PromiseInterface;
use Onion\Framework\Promise\Interfaces\ThenableInterface;

if (!function_exists(__NAMESPACE__ . '\is_thenable')) {
    function is_thenable($value): bool
    {
        return (
            is_object($value) && method_exists($value, 'then')
        ) || $value instanceof ThenableInterface;
    }
}

$coroutineExists = function_exists(__NAMESPACE__ . '\coroutine');

if (!$coroutineExists) {
    if (!defined('SWOOLE_HOOK_ALL')) {
        define('SWOOLE_HOOK_ALL', 0);
    }

    if (!function_exists('go')) {
        function go() {
            // here to silence psalm
        }
    }
    if (extension_loaded('swoole') && function_exists('go')) {
        if (method_exists('\Swoole\Runtime', 'enableCoroutine')) {
            \Swoole\Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);
        }

        function coroutine(Closure $task): PromiseInterface {
            return new Promise(function ($resolve, $reject) use ($task) {
                go(function() use ($task, $resolve, $reject) {
                    try {
                        $resolve($task());
                    } catch (\Throwable $ex) {
                        $reject($ex);
                    }
                });
            });
        };

    } else {
        function &queue() {
            static $queue = null;

            if ($queue === null) {
                $queue = new class {
                    /** @var \SplQueue $queue */
                    private $queue;
                    public function __construct()
                    {
                        $this->queue = new \SplQueue;
                        $this->queue->setIteratorMode(\SplQueue::IT_MODE_DELETE);
                    }

                    public function add(Closure $callback)
                    {
                        $this->queue->enqueue($callback);
                    }

                    public function tick(int $count = 1)
                    {
                        if ($this->queue->isEmpty()) {
                            return;
                        }

                        for ($i = 0; $i < ($count ?: PHP_INT_MAX); $i++) {
                            if ($this->queue->isEmpty()) {
                                break;
                            }

                            $callback = $this->queue->dequeue();

                            $callback();
                        }
                    }

                    public function run()
                    {
                        while (!$this->queue->isEmpty()) {
                            $this->tick();
                        }
                    }
                };
            }

            return $queue;
        }

        function coroutine(Closure $task): PromiseInterface
        {
            return new Promise(function ($resolve, $reject) use ($task) {
                queue()->add(function () use ($task, $resolve, $reject) {
                    try {
                        $resolve($task());
                    } catch (\Throwable $ex) {
                        $reject($ex);
                    }
                });
            });
        }

        register_tick_function([queue(), 'tick'], 1);
        register_shutdown_function([queue(), 'run']);
    }
}

if (!function_exists(__NAMESPACE__ . '\async')) {
    function async(Closure $callback) {
        return coroutine($callback);
    }
}
