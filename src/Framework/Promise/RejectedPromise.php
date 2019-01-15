<?php
namespace Onion\Framework\Promise;

class RejectedPromise extends Promise
{
    public function __construct(\Throwable $reason)
    {
        parent::__construct();
        $this->reject($value);
    }
}
