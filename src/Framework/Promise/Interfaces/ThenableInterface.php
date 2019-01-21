<?php
namespace Onion\Framework\Promise\Interfaces;

use Closure;

interface ThenableInterface
{
    public function then(?Closure $onFulfilled = null, ?Closure $onRejected = null): ThenableInterface;
}
