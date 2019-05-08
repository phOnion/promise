<?php
namespace Onion\Framework\Promise\Interfaces;

interface AwaitableInterface extends PromiseInterface
{
    public function await();
}
