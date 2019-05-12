<?php
namespace Onion\Framework\Promise;

use Onion\Framework\Promise\Interfaces\CancelableInterface;
use Onion\Framework\Promise\Promise;

class CancelablePromise extends Promise implements CancelableInterface
{
    private $cancelFn;

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
        $this->setState(static::CANCELLED);
        if ($this->cancelFn !== null) {
            call_user_func($this->cancelFn);
        }
    }
}
