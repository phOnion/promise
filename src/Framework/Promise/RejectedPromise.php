<?php
namespace Onion\Framework\Promise;

class RejectedPromise extends Promise
{
    public function __construct(\Throwable $reason)
    {
        parent::__construct(function ($resolve, $reject) use ($reason) {
            $reject($reason);
        });
    }
}
