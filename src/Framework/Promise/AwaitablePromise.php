<?php
namespace Onion\Framework\Promise;

use Onion\Framework\Promise\Interfaces\AwaitableInterface;

class AwaitablePromise extends CancelablePromise implements AwaitableInterface
{
    private $waitFn;

    public function __construct(callable $task, callable $waitFn, callable $cancelFn = null)
    {
        $this->waitFn = $waitFn;
        parent::__construct($task, $cancelFn ?? function () {});
    }

    public function await()
    {
        if ($this->isPending() && is_callable($this->waitFn)) {
            call_user_func($this->waitFn);

            if ($this->getValue() instanceof AwaitableInterface) {
                return $this->getValue()->await();
            }
        }

        if ($this->isFulfilled()) {
            return $this->getValue();
        }

        if ($this->isRejected()) {
            throw $this->getValue();
        }

        throw new \RuntimeException("Waiting on {$this->getState()} promise failed");
    }
}
