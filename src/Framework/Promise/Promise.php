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

    /** @var \Closure $cancelFn */
    private $cancelFn;

    /** @var \Closure $waitFn */
    private $waitFn;

    public function __construct(Closure $task = null, Closure $wait = null, Closure $cancel = null)
    {
        $this->fulfilledQueue = new \SplQueue();
        $this->fulfilledQueue->setIteratorMode(\SplQueue::IT_MODE_DELETE);

        $this->rejectedQueue = new \SplQueue();
        $this->rejectedQueue->setIteratorMode(\SplQueue::IT_MODE_DELETE);

        $this->finallyQueue = new \SplQueue();
        $this->finallyQueue->setIteratorMode(\SplQueue::IT_MODE_DELETE);

        if ($task !== null) {
            try {
                $task(function ($value) {
                    $this->resolve($value);
                }, function ($value) {
                    $this->reject($value);
                });
            } catch (\Throwable $ex) {
                if ($this->isRejected()) {
                    $this->state = self::PENDING;
                }

                $this->reject($ex);
            }
        }

        $this->waitFn = $wait;
        $this->cancelFn = $cancel;
    }

    public function resolve($value): void
    {
        $this->settle(self::FULFILLED, $this->fulfilledQueue, $value);
    }

    public function reject(\Throwable $ex): void
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

    private function settle(string $state, \SplQueue $queue, $result)
    {
        if (!$this->isPending()) {
            throw new \LogicException("Promise already {$this->getState()}");
        }
        $this->value = $result;
        $this->state = $state;

        try {
            while (!$queue->isEmpty() && ($callback = $queue->dequeue())) {
                $this->value = $callback($this->value) ?? $this->value;

                if ($this->value === $this) {
                    throw new \InvalidArgumentException('Unable to process promise with itself');
                }

                if ($this->handleResult($this->value)) {
                    break;
                }
            }
        } catch (\Throwable $ex) {
            $this->state = self::PENDING;
            $this->reject($ex);
        } finally {
            if (!$this->isPending()) {
                $finally = $this->finallyQueue;

                while (!$finally->isEmpty() && ($callback = $finally->dequeue())) {
                    $callback();
                }
            }
        }
    }

    private function handleResult(&$result)
    {
        if (is_thenable($result)) {
            $this->state = self::PENDING;
            if ($result instanceof self) {
                $this->state = $result->getState();
            }

            try {
                $result->then(function ($value) {
                    $this->resolve($value);
                }, function ($reason) {
                    $this->reject($reason);
                });
            } catch (\Exception $ex) {
                $this->reject($ex);
            }

            return true;
        }

        if ($result instanceof \Closure) {
            $result = $result(function ($value) {
                if (!$this->isPending()) {
                    $this->resolve($value);
                }
            }, function ($value) {
                if (!$this->isPending()) {
                    $this->reject($value);
                }
            });
        }

        return false;
    }

    public function then(?Closure $onFulfilled = null, ?Closure $onRejected = null): ThenableInterface
    {
        try {
            if ($this->isFulfilled()) {
                $this->value = $onFulfilled($this->value) ?? $this->value;
                $this->handleResult($this->value);

                return $this;
            }

            if ($onRejected !== null) {
                $this->otherwise($onRejected);
            }

            if ($onFulfilled) {
                $this->fulfilledQueue->enqueue($onFulfilled);
            }
        } catch (\Throwable $ex) {
            if ($this->isPending()) {
                $this->reject($ex);
            }
        }

        return $this;
    }

    public function otherwise(Closure $onRejected): PromiseInterface
    {

        if ($this->getState() === self::REJECTED) {
            $onRejected($this->value) ?? $this->value;
            $this->handleResult($this->value);
            return $this;
        }

        $this->rejectedQueue->enqueue($onRejected);

        return $this;
    }

    public function finally(Closure ...$callback): PromiseInterface
    {
        if (!$this->isPending()) {
            foreach ($callback as $cb) {
                $cb();
            }

            return $this;
        }

        foreach ($callback as $cb) {
            $this->finallyQueue->enqueue($cb);
        }

        return $this;
    }

    private function getState(): string
    {
        return $this->state;
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
     *
     * @return PromiseInterface
     */
    public static function all(iterable $promises): PromiseInterface
    {
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


        return $result->then(function ($results) {
            ksort($results);

            return $results;
        });
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
