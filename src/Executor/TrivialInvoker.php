<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Executor;

/**
 * Minimal callable invoker.
 *
 * Invokes the given callable without resolving or passing any arguments.
 * The callable must therefore define no required parameters.
 */
final readonly class TrivialInvoker implements InvokesCallable
{
    public function invoke(callable $fn): mixed
    {
        return $fn();
    }
}
