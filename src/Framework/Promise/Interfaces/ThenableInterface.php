<?php
namespace Onion\Framework\Promise\Interfaces;

use Closure;

interface ThenableInterface
{
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): ThenableInterface;
}
