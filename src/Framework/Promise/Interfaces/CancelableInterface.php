<?php
namespace Onion\Framework\Promise\Interfaces;

interface CancelableInterface
{
    public function cancel(): void;
    public function isCanceled(): bool;
}
