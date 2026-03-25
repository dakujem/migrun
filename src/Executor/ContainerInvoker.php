<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Executor;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionNamedType;

/**
 * Default callable invoker backed by a PSR-11 container.
 *
 * For each parameter of the callable:
 *   - Named, non-builtin type  → fetched from the container by FQCN.
 *   - Built-in or untyped      → default value if declared, otherwise null.
 */
final readonly class ContainerInvoker implements InvokesCallable
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    public function invoke(callable $fn): mixed
    {
        $ref = new ReflectionFunction($fn(...));

        $args = array_map(function ($param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                return $this->container->get($type->getName());
            }

            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }

            return null;
        }, $ref->getParameters());

        return $fn(...$args);
    }
}
