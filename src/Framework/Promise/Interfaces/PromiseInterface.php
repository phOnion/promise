<?php
namespace Onion\Framework\Promise\Interfaces;

use Closure;

interface PromiseInterface extends ThenableInterface
{
    public function otherwise(Closure $onRejected): PromiseInterface;
    public function finally(Closure ...$final): PromiseInterface;

    public function resolve($value): void;
    public function reject(\Throwable $reason): void;

    public function isPending(): bool;
    public function isFulfilled(): bool;
    public function isRejected(): bool;
}
