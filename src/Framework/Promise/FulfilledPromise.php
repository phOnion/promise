<?php
namespace Onion\Framework\Promise;

class FulfilledPromise extends Promise
{
    public function __construct($value)
    {
        parent::__construct();
        $this->resolve($value);
    }
}
