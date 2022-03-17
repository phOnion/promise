<?php

namespace Onion\Framework\Promise;

use InvalidArgumentException;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Promise\Interfaces\ThenableInterface;

use function Onion\Framework\Loop\signal;

if (!function_exists(__NAMESPACE__ . '\is_thenable')) {
    function is_thenable(mixed $value): bool
    {
        return $value instanceof ThenableInterface ||
            (is_object($value) && method_exists($value, 'then'));
    }
}


if (!function_exists(__NAMESPACE__ . '\await')) {
    function await(mixed $promise): mixed
    {
        if (!is_thenable($promise)) {
            throw new InvalidArgumentException(
                "Provided argument must either contain a 'then' method " .
                    "or implement Onion\Framework\Promise\Interfaces\ThenableInterface"
            );
        }

        return signal(function (callable $resume, TaskInterface $task, SchedulerInterface $scheduler) use ($promise) {
            $promise

                ->then(
                    $resume,
                    function (\Throwable $ex) use ($task, $scheduler) {
                        $task->throw($ex);
                        $scheduler->schedule($task);
                    }
                );
        });
    }
}
