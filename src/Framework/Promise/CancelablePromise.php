<?php
namespace Onion\Framework\Promise;

use Onion\Framework\Promise\Interfaces\CancelableInterface;
use Onion\Framework\Promise\Promise;

class CancelablePromise extends Promise implements CancelableInterface
{
    private $cancelFn;
    private $state;

    public function __construct(callable $task, callable $cancelFn)
    {
        parent::__construct($task);

        $this->cancelFn = $cancelFn;
    }

    public function isCanceled(): bool
    {
        return $this->getState() === static::CANCELLED;
    }

    public function cancel(): void
    {
        if ($this->isPending()) {
            $this->state = static::CANCELLED;
            if ($this->cancelFn !== null) {
                call_user_func($this->cancelFn);
            }
        }
    }
}
