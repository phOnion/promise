<?php
namespace Onion\Framework\Promise;

class FulfilledPromise extends Promise
{
    public function __construct($value)
    {
        parent::__construct(function ($resolve, $reject) use ($value) {
            $resolve($value);
        });
    }
}
