<?php
namespace Onion\Framework\Promise;

use Onion\Framework\Promise\Interfaces\ThenableInterface;

if (!function_exists(__NAMESPACE__ . '\is_thenable')) {
    function is_thenable($value): bool
    {
        return (
            is_object($value) && method_exists($value, 'then')
        ) || $value instanceof ThenableInterface;
    }
}
