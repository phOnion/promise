<?php
namespace Onion\Framework\Promise\Interfaces;

use Closure;

interface PromiseInterface extends ThenableInterface
{
    public const PENDING = 'pending';
    public const REJECTED = 'rejected';
    public const FULFILLED = 'fulfilled';
    public const CANCELLED = 'cancelled';

    public function otherwise(callable $onRejected): self;
    public function finally(callable ...$final): self;

    public function isPending(): bool;
    public function isFulfilled(): bool;
    public function isRejected(): bool;
}
