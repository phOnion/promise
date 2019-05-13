<?php declare(strict_types=1);
namespace Onion\Framework\Promise;

use function Onion\Framework\EventLoop\loop;
use Onion\Framework\Promise\Interfaces\CancelableInterface;
use Onion\Framework\Promise\Interfaces\PromiseInterface;
use Onion\Framework\Promise\Interfaces\ThenableInterface;

class Promise implements PromiseInterface
{
    protected const ALLOWED_STATES = [
        self::PENDING,
        self::FULFILLED,
        self::REJECTED,
        self::CANCELLED,
    ];

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
        $this->setState(static::PENDING);

        $this->fulfilledQueue = new \SplQueue();
        $this->rejectedQueue = new \SplQueue();
        $this->finallyQueue = new \SplQueue();

        if ($task !== null) {
            try {
                call_user_func($task, function ($value): void {
                    $this->resolve($value);
                }, function (\Throwable $value): void {
                    $this->reject($value);
                });
            } catch (\Throwable $ex) {
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

    protected function setState(string $state): void
    {
        $this->state = $state;
    }

    protected function getValue()
    {
        return $this->value;
    }

    private function settle(string $state, \SplQueue $queue, $result)
    {
        if ($this->getState() === static::CANCELLED) {
            return;
        }

        if ($result === $this) {
            throw new \InvalidArgumentException('Unable to process promise with itself');
        }

        if ($this->getState() !== $state && !$this->isPending()) {
            throw new \LogicException("Promise already {$this->getState()}");
        }

        $this->value = $result;
        $this->setState($state);

        $this->handleResult($this->value);
        if ($this->isPending()) {
            return;
        }

        try {
            if ($queue->isEmpty() && !$this->isPending()) {
                $finally = $this->finallyQueue;

                while (!$finally->isEmpty() && ($callback = $finally->dequeue())) {
                    call_user_func($callback);
                }
                return;
            }

            $this->value = call_user_func($queue->dequeue(), $this->value) ?? $this->value;

            if ($this->value instanceof \Throwable) {
                throw $this->value;
            }

            $this->setState(static::PENDING);
            $this->resolve($this->value);
        } catch (\Throwable $ex) {
            $this->setState(static::PENDING);
            $this->reject($ex);
        }
    }

    private function handleResult(&$result)
    {
        if (is_thenable($result)) {
            $this->setState(static::PENDING);
            if ($result instanceof CancelableInterface && $result->isCanceled()) {
                $this->setState(static::CANCELLED);
            }

            $result->then(function ($value) {
                $this->resolve($value);
            }, function ($reason) {
                $this->reject($reason);
            });
        }

        if (is_callable($result)) {
            $this->setState(static::PENDING);
            call_user_func(
                $result,
                function ($value) {
                    $this->resolve($value);
                },
                function ($value) {
                    $this->reject($value);
                }
            );
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
                call_user_func($cb);
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
        return new self(function ($resolve, $reject) use ($promises) {
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
            if ($promise->isPending()) {
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
        }

        return $promise;
    }
}
