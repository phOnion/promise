<?php

namespace Onion\Framework\Promise;

use Onion\Framework\Promise\Interfaces\AwaitableInterface;

class AwaitablePromise extends Promise implements AwaitableInterface
{
    public function await(): mixed
    {
        return await($this);
    }
}
