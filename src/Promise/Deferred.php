<?php

namespace Onion\Framework\Promise;

use Closure;
use Onion\Framework\Promise\Interfaces\DeferredInterface;
use Onion\Framework\Promise\Interfaces\PromiseInterface;
use RuntimeException;
use Throwable;

class Deferred implements DeferredInterface
{
    private readonly mixed $value;
    private readonly Throwable $exception;


    private readonly PromiseInterface $promise;

    private readonly Closure $resolveFn;
    private readonly Closure $rejectFn;

    private bool $complete = false;


    public function __construct()
    {
        $this->promise = new Promise(function (Closure $resolve, Closure $reject) {
            if ($this->complete) {
                if (!isset($this->exception)) {
                    $resolve($this->value);
                } else {
                    $reject($this->exception);
                }
            }

            $this->resolveFn ??= $resolve;
            $this->rejectFn ??= $reject;
        });
    }

    public function promise(): PromiseInterface
    {
        return $this->promise;
    }

    public function resolve(mixed $value): void
    {
        if ($this->complete) {
            throw new RuntimeException('Deferred already completed');
        }
        $this->complete = true;
        $this->value = $value;

        if (isset($this->resolveFn)) {
            ($this->resolveFn)($this->value);
        }
    }

    public function reject(Throwable $ex): void
    {
        if ($this->complete) {
            throw new RuntimeException('Deferred already completed');
        }

        $this->complete = true;
        $this->exception = $ex;

        if (isset($this->rejectFn)) {
            ($this->rejectFn)($this->exception);
        }
    }

    public function complete(): bool
    {
        return $this->complete
    }
}
