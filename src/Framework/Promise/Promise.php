<?php declare(strict_types=1);
namespace Onion\Framework\Promise;

use Closure;
use Onion\Framework\Promise\Interfaces\PromiseInterface;
use Onion\Framework\Promise\Interfaces\ThenableInterface;
use Onion\Framework\Promise\Interfaces\CancelableInterface;
use Onion\Framework\Promise\Interfaces\WaitableInterface;

class Promise implements
    PromiseInterface,
    CancelableInterface,
    WaitableInterface
{
    private const PENDING = 'pending';
    private const REJECTED = 'rejected';
    private const FULFILLED = 'fulfilled';
    private const CANCELLED = 'cancelled';

    private $state = self::PENDING;
    private $value;

    /** @var \SplQueue $fulfilledQueue */
    private $fulfilledQueue;

    /** @var \SplQueue $rejectedQueue */
    private $rejectedQueue;

    /** @var \SplQueue $finallyQueue */
    private $finallyQueue;

    /** @var \Closure|null $cancelFn */
    private $cancelFn;

    /** @var \Closure|null $waitFn */
    private $waitFn;

    public function __construct(?Closure $task = null, ?Closure $wait = null, ?Closure $cancel = null)
    {
        $this->fulfilledQueue = new \SplQueue();
        $this->rejectedQueue = new \SplQueue();
        $this->finallyQueue = new \SplQueue();

        $this->waitFn = $wait;
        $this->cancelFn = $cancel;

        if ($task !== null) {
            try {
                $task(function ($value): void {
                    $this->resolve($value);
                }, function (\Throwable $value): void {
                    $this->reject($value);
                });
            } catch (\Throwable $ex) {
                if ($this->isRejected()) {
                    $this->state = self::PENDING;
                }

                $this->reject($ex);
            }
        }
    }

    private function resolve($value): void
    {
        $this->settle(self::FULFILLED, $this->fulfilledQueue, $value);
    }

    private function reject(\Throwable $ex): void
    {
        $this->settle(self::REJECTED, $this->rejectedQueue, $ex);
    }

    public function cancel(): void
    {
        if ($this->isPending()) {
            $this->state = self::CANCELLED;
            if ($this->cancelFn instanceof Closure) {
                ($this->cancelFn)();
            }
        }
    }

    private function getState(): string
    {
        return $this->state;
    }

    private function settle(string $state, \SplQueue $queue, $result)
    {
        if ($this->getState() !== $state && !$this->isPending()) {
            throw new \LogicException("Promise already {$this->getState()}");
        }

        $this->value = $result;
        $this->state = $state;

        try {
            if ($queue->isEmpty()) {
                $finally = $this->finallyQueue;

                while (!$finally->isEmpty() && ($callback = $finally->dequeue())) {
                    $callback();
                }
                return;
            }
            $callback = $queue->dequeue();
            $this->value = $callback($this->value) ?? $this->value;

            if ($this->handleResult($this->value)) {
                if ($this->isPending()) {
                    return;
                }
            }

            if ($this->value instanceof \Throwable) {
                return $this->reject($this->value);
            }

            $this->state = self::PENDING;
            $this->resolve($this->value);
        } catch (\Throwable $ex) {
            $this->state = self::PENDING;
            $this->reject($ex);
        }
    }

    private function handleResult(&$result)
    {
        if ($result === $this) {
            throw new \InvalidArgumentException('Unable to process promise with itself');
        }

        if (is_thenable($result)) {
            $this->state = self::PENDING;
            if ($result instanceof PromiseInterface) {
                if ($result->isFulfilled()) {
                    $this->state = self::FULFILLED;
                }

                if ($result->isRejected()) {
                    $this->state = self::REJECTED;
                }

                if ($result instanceof CancelableInterface) {
                    if ($result->isCanceled()) {
                        $this->state = self::CANCELLED;
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
            $this->state = self::PENDING;
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

    public function await()
    {
        if ($this->isPending() && $this->waitFn instanceof Closure) {
            ($this->waitFn)();
        }

        if ($this->isFulfilled()) {
            return $this->value;
        }

        if ($this->isRejected()) {
            throw $this->value;
        }

        throw new \LogicException("Waiting on {$this->getState()} promise failed");
    }

    public function then(?Closure $onFulfilled = null, ?Closure $onRejected = null): ThenableInterface
    {
        if ($onFulfilled) {
            $this->fulfilledQueue->enqueue($onFulfilled);
        }

        if ($this->isFulfilled()) {
            $this->settle(self::FULFILLED, $this->fulfilledQueue, $this->value);
        }

        if ($onRejected !== null) {
            $this->otherwise($onRejected);
        }

        return $this;
    }

    public function otherwise(Closure $onRejected): PromiseInterface
    {

        $this->rejectedQueue->enqueue($onRejected);
        if ($this->getState() === self::REJECTED) {
            $this->settle(self::REJECTED, $this->rejectedQueue, $this->value);
        }

        return $this;
    }

    public function finally(Closure ...$callback): PromiseInterface
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
        return $this->getState() === self::PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->getState() === self::FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->getState() === self::REJECTED;
    }

    public function isCanceled(): bool
    {
        return $this->getState() === self::CANCELLED;
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
        $promise = new self();

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
