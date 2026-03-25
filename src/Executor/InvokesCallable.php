<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Executor;

/**
 * Invokes a callable, resolving its arguments automatically.
 *
 * Implementations may use a PSR-11 container, a DI framework invoker,
 * or any other strategy to map typed parameters to concrete values.
 */
interface InvokesCallable
{
    /**
     * Calls the given callable with automatically resolved arguments
     * and returns its return value.
     */
    public function invoke(callable $fn): mixed;
}
