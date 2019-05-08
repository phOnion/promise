<?php declare(strict_types=1);
namespace Onion\Framework\Promise;

use Onion\Framework\Promise\Interfaces\PromiseInterface;
use Onion\Framework\Promise\Interfaces\ThenableInterface;
use Onion\Framework\Promise\Interfaces\CancelableInterface;
use function Onion\Framework\EventLoop\loop;
use function Onion\Framework\EventLoop\coroutine;

class Promise implements PromiseInterface
{
    private $state;
    private $value;

    /** @var \SplQueue $fulfilledQueue */
    private $fulfilledQueue;

    /** @var \SplQueue $rejectedQueue */
    private $rejectedQueue;

    /** @var \SplQueue $finallyQueue */
    private $finallyQueue;

    public function __construct(?callable $task = null)
    {
        $this->state = static::PENDING;

        $this->fulfilledQueue = new \SplQueue();
        $this->rejectedQueue = new \SplQueue();
        $this->finallyQueue = new \SplQueue();

        if ($task !== null) {
            try {
                $task(function ($value): void {
                    $this->resolve($value);
                }, function (\Throwable $value): void {
                    $this->reject($value);
                });
            } catch (\Throwable $ex) {
                if ($this->isRejected()) {
                    $this->state = static::PENDING;
                }

                $this->reject($ex);
            }
        }
    }

    private function resolve($value): void
    {
        $this->settle(static::FULFILLED, $this->fulfilledQueue, $value);
    }

    private function reject(\Throwable $ex): void
    {
        $this->settle(static::REJECTED, $this->rejectedQueue, $ex);
    }

    protected function getState(): string
    {
        return $this->state;
    }

    private function settle(string $state, \SplQueue $queue, $result)
    {
        if ($this->getState() === static::CANCELLED) {
            return;
        }

        if ($this->getState() !== $state && !$this->isPending()) {
            throw new \LogicException("Promise already {$this->getState()}");
        }

        $this->value = $result;
        $this->state = $state;

        try {
            if ($queue->isEmpty() && !$this->isPending()) {
                $finally = $this->finallyQueue;

                while (!$finally->isEmpty() && ($callback = $finally->dequeue())) {
                    call_user_func($callback);
                }
                return;
            }

            $this->handleResult($this->value);
            if ($this->isPending()) {
                return;
            }

            $this->value = call_user_func($queue->dequeue(), $this->value) ?? $this->value;
            if ($this->value instanceof \Throwable) {
                return $this->reject($this->value);
            }

            $this->state = static::PENDING;
            $this->resolve($this->value);
        } catch (\Throwable $ex) {
            $this->state = static::PENDING;
            $this->reject($ex);
        }
    }

    private function handleResult(&$result)
    {
        if ($result === $this) {
            throw new \InvalidArgumentException('Unable to process promise with itself');
        }

        if (is_thenable($result)) {
            $this->state = static::PENDING;
            if ($result instanceof PromiseInterface) {
                if ($result->isFulfilled()) {
                    $this->state = static::FULFILLED;
                }

                if ($result->isRejected()) {
                    $this->state = static::REJECTED;
                }

                if ($result instanceof CancelableInterface) {
                    if ($result->isCanceled()) {
                        $this->state = static::CANCELLED;
                    }
                }
            }

            $result->then(function ($value) {
                $this->resolve($value);
            }, function ($reason) {
                $this->reject($reason);
            });
        }

        if ($result instanceof \Closure) {
            $this->state = static::PENDING;
            $result(function ($value) {
                if ($this->isPending()) {
                    $this->resolve($value);
                }
            }, function ($value) {
                if ($this->isPending()) {
                    $this->reject($value);
                }
            });
        }
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): ThenableInterface
    {
        if ($onFulfilled) {
            $this->fulfilledQueue->enqueue($onFulfilled);
        }

        if ($this->isFulfilled()) {
            $this->settle(static::FULFILLED, $this->fulfilledQueue, $this->value);
        }

        if ($onRejected !== null) {
            $this->otherwise($onRejected);
        }

        return $this->value instanceof ThenableInterface ? $this->value : $this;
    }

    public function otherwise(callable $onRejected): PromiseInterface
    {

        $this->rejectedQueue->enqueue($onRejected);
        if ($this->getState() === static::REJECTED) {
            $this->settle(static::REJECTED, $this->rejectedQueue, $this->value);
        }

        return $this;
    }

    public function finally(callable ...$callback): PromiseInterface
    {
        foreach ($callback as $cb) {
            if ($this->isFulfilled() || $this->isRejected()) {
                $cb();
                continue;
            }

            $this->finallyQueue->enqueue($cb);
        }

        return $this;
    }

    public function isPending(): bool
    {
        return $this->getState() === static::PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->getState() === static::FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->getState() === static::REJECTED;
    }

    public function isCanceled(): bool
    {
        return $this->getState() === static::CANCELLED;
    }

    /**
     * Attempt to resolve all promises, if 1 fails
     * the whole promise is rejected with it's reason
     *
     * @var ThenableInterface[] $promises
     */
    public static function all(iterable $promises): PromiseInterface
    {
        if ($promises instanceof \Iterator) {
            $promises = iterator_to_array($promises);
        }

        /** @var array $promises */
        $result = new self(function ($resolve, $reject) use ($promises) {
            $results = [];
            $count = count($promises);
            foreach ($promises as $index => $promise) {
                assert(
                    is_thenable($promise),
                    new \InvalidArgumentException("Item {$index} is not thenable")
                );

                $promise->then(function ($value) use (&$results, $index, $count, $resolve) {
                    $results[$index] = $value;

                    if (count($results) === $count) {
                        $resolve($results);
                    }
                }, function ($reason) use ($reject) {
                    $reject($reason);
                });
            }
        }, function () {
            loop()->tick();
        });


        $result->then(function ($results): array {
            ksort($results);

            return $results;
        });

        return $result;
    }

    /**
     * Race a group of promises and either resolve/reject this one
     * based on the response from the first promise that completed
     *
     * @var ThenableInterface[] $promises
     *
     * @return PromiseInterface
     */
    public static function race(iterable $promises): PromiseInterface
    {
        $promise = new self(null, function () {
            loop()->tick();
        });

        foreach ($promises as $index => $item) {
            assert(
                is_thenable($item),
                new \InvalidArgumentException("Item {$index} is not thenable")
            );
            if (!$promise->isPending()) {
                break;
            }

            $item->then(function ($value) use (&$promise) {
                if ($promise->isPending()) {
                    $promise->resolve($value);
                }
            }, function ($reason) use (&$promise) {
                if ($promise->isPending()) {
                    $promise->reject($reason);
                }
            });
        }

        return $promise;
    }
}
