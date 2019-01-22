# Introduction


[![Build Status](https://travis-ci.org/phOnion/promise.svg?branch=master)](https://travis-ci.org/phOnion/promise)
<!-- [![Mutation testing badge](https://badge.stryker-mutator.io/github.com/phOnion/promise/master)](https://infection.github.io) -->

---------------

This is an [Promises/A+](https://promisesaplus.com/) implementation that should be fully compatible with any already existing implementations without any 3rd party dependencies.

This package defines 2 interfaces `PromiseInterface` & `ThenableInterface` as per the spec also there are some helper functions as well as some that provide a more
sugary feel to the code.

`is_thenable` - Function that checks if an object is "thenable"
`coroutine` - Push a task as a promise. This one changes it's implementation depending on whether or not the `swoole` extension is
available on the server. If it is then the native coroutines available as `go` function are used, alternatively it falls back to an approach similar to an event loop.

A tick function is registered using `register_tick_function` that will invoke 1 task per so that not to bring your application to a complete halt when executing. As well as there is a function registered for shutdown that will run all remaining tasks in a blocking way until the queue is empty - albeit hanging the request.

For when the synchronous mode is used, you should do `declare(ticks=1)` see [the PHP Docs]( https://secure.php.net/manual/en/control-structures.declare.php#control-structures.declare.ticks)

_**NOTE**: Please, keep in mind that your code will block until the task is processed so this 'mode' is not recommended and users should look at alternative approaches for their heavy tasks or install `swoole`_

`async` - alias for `coroutine`, intended to mimic the `async` keyword in JS/TS/C#

==============

## Usage

```php
use \Onion\Framework\Promise\async;

$promise = async(function () use ($orm, $id, $password) {
    $user = $orm->findById($id); // if it throws any exception the promise will immediately get rejected

    if (!$user->isActive()) {
        throw new \RuntimeException('User is not activated yet');
    }

    if (!password_verify($password, $user->getPassword())) {
        throw new \InvalidArgumentException('Invalid password provided');
    }

    return $user;
})->then(function (User $user) use ($paymentService) {
    $paymentService->processUserPayment($user->getId());
    echo "User {$user->getId()} processed successfully";
}, function (\Throwable $ex) {
    echo "Ops.. {$ex->getMessage()}";
})->otherwise(function (\Throwable $ex) {
    // Do something else with the exception maybe ?
})->finally(function () use ($orm) {
    // Regardless of the state change, but it is always called at the end of execution
    $orm->disconnect();
});

```

The function passed to `async`/`coroutine` will be executed immediately so do keep in mind that
when using without `swoole`.
