<?php
namespace Onion\Framework\Promise;

use Onion\Framework\Promise\Interfaces\AwaitableInterface;

class AwaitablePromise extends CancelablePromise implements AwaitableInterface
{
    private $waitFn;

    public function __construct(callable $task, callable $waitFn, callable $cancelFn = null)
    {
        parent::__construct($task, $cancelFn);
        $this->waitFn = $waitFn;
    }

    public function await()
    {
        if ($this->isPending() && $this->waitFn instanceof Closure) {
            call_user_func($this->waitFn);

            if ($this->value instanceof AwaitableInterface) {
                return $this->value->await();
            }
        }

        if ($this->isFulfilled()) {
            return $this->value;
        }

        if ($this->isRejected()) {
            throw $this->value;
        }

        throw new \LogicException("Waiting on {$this->getState()} promise failed");
    }
}
