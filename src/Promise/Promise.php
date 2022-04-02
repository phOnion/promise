<?php

declare(strict_types=1);

namespace Onion\Framework\Promise;

use Closure;
use LogicException;
use Onion\Framework\Promise\Interfaces\PromiseInterface;
use Onion\Framework\Promise\Types\State;
use SplQueue;
use Throwable;

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\tick;

class Promise implements PromiseInterface
{
    private mixed $value = null;
    private ?Throwable $exception = null;
    private State $state = State::PENDING;

    private readonly SplQueue $queue;

    final public function __construct(callable $fn)
    {
        $this->queue = new \SplQueue();

        coroutine(function (callable $resolve, callable $reject) use (&$fn) {
            try {
                $fn($resolve, $reject);
            } catch (Throwable $ex) {
                $this->doReject($ex);
            }
        }, [
            function (mixed $v) {
                if ($this->state === State::PENDING) {
                    $this->doResolve($v);
                }
            },
            function (Throwable $e) {
                if ($this->state === State::PENDING) {
                    $this->doReject($e);
                }
            },
        ]);
    }

    private function doResolve(mixed $value): void
    {
        if ($value === $this) {
            throw new LogicException('Unable to resolve promise with itself');
        }

        if (is_thenable($value)) {
            $this->state = State::PENDING;
            $value->then(
                fn ($v) => $this->doResolve($v),
                fn ($e) => $this->doReject($e),
            );
        } else {
            $this->value = $value;
            $this->settle(State::FULFILLED);
        }
    }

    private function doReject(Throwable $ex): void
    {
        $this->exception = $ex;
        $this->settle(State::REJECTED);
    }

    private function settle(State $state): void
    {
        $this->state = $state;

        while (!$this->queue->isEmpty() && $this->state !== State::PENDING) {
            [$when, $fn] = $this->queue->dequeue();

            if ($when === $state) {
                $this->invoke($state, $fn);
            }
        }
    }

    private function invoke(State $state, callable $fn): void
    {
        tick();
        try {
            $this->doResolve(
                Closure::fromCallable($fn)(match ($state) {
                    State::FULFILLED => $this->value,
                    State::REJECTED => $this->exception,
                })
            );
        } catch (\Throwable $ex) {
            $this->doReject($ex);
        }
    }

    public function then(callable $onFulfilled = null, callable $onRejected = null): static
    {
        if ($onFulfilled) {
            $this->queue->enqueue([State::FULFILLED, $onFulfilled]);
        }

        if ($onRejected) {
            $this->queue->enqueue([State::REJECTED, $onRejected]);
        }

        $this->settle($this->state);

        return $this;
    }

    public function catch(callable $onRejected): static
    {
        return $this->then(null, $onRejected);
    }

    public function finally(callable $onFinally): static
    {
        $wrapper = function (mixed $value) use (&$onFinally): mixed {
            call_user_func($onFinally);

            return $value;
        };

        return $this->then($wrapper, $wrapper);
    }

    public static function resolve(mixed $value): static
    {
        return new static(fn (callable $resolve): mixed => $resolve($value));
    }

    public static function reject(Throwable $ex): static
    {
        return new static(fn (callable $_, callable $reject): mixed => $reject($ex));
    }

    public static function all(PromiseInterface ...$promises): static
    {
        return new static(function ($resolve, $reject) use (&$promises) {
            $result = [];
            $total = count($promises);
            foreach ($promises as $idx => $promise) {
                $promise->then(function ($value) use ($total, $idx, &$result, &$resolve) {
                    $result[$idx] = $value;

                    if (count($result) === $total) {
                        $resolve($result);
                    }

                    return $value;
                }, $reject);
            }
        });
    }

    public static function race(PromiseInterface ...$promises): static
    {
        return new static(function ($resolve, $reject) use (&$promises) {
            $resolved = false;

            foreach ($promises as $promise) {
                $promise->then(function ($value) use (&$resolve, &$resolved) {
                    if (!$resolved) {
                        $resolve($value);
                        $resolved = true;
                    }
                }, function ($ex) use (&$reject, &$resolved) {
                    if (!$resolved) {
                        $reject($ex);
                        $resolved = true;
                    }
                });
            }
        });
    }
}
