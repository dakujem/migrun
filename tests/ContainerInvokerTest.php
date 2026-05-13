<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Executor\ContainerInvoker;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ContainerInvokerTest extends TestCase
{
    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Nothing in container.");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    // -------------------------------------------------------------------------
    // No parameters
    // -------------------------------------------------------------------------

    public function testInvokesCallableWithNoParameters(): void
    {
        $invoker = new ContainerInvoker($this->emptyContainer());
        $result = $invoker->invoke(fn() => 'called');
        self::assertSame('called', $result);
    }

    // -------------------------------------------------------------------------
    // Built-in typed parameter — null is passed (callable must accept null)
    // -------------------------------------------------------------------------

    public function testBuiltinTypedParamReceivesNull(): void
    {
        $invoker = new ContainerInvoker($this->emptyContainer());
        $captured = 'untouched';
        $invoker->invoke(function (?string $s) use (&$captured) {
            $captured = $s;
        });
        self::assertNull($captured);
    }

    // -------------------------------------------------------------------------
    // Untyped parameter — falls back to null
    // -------------------------------------------------------------------------

    public function testUntypedParamReceivesNull(): void
    {
        $invoker = new ContainerInvoker($this->emptyContainer());
        $captured = 'untouched';
        $invoker->invoke(function ($x) use (&$captured) {
            $captured = $x;
        });
        self::assertNull($captured);
    }

    // -------------------------------------------------------------------------
    // Built-in typed parameter with default — uses the declared default value
    // -------------------------------------------------------------------------

    public function testBuiltinTypedParamWithDefaultUsesDefault(): void
    {
        $invoker = new ContainerInvoker($this->emptyContainer());
        $captured = 'untouched';
        $invoker->invoke(function (int $n = 42) use (&$captured) {
            $captured = $n;
        });
        self::assertSame(42, $captured);
    }

    // -------------------------------------------------------------------------
    // Named (non-builtin) type — fetched from the container
    // -------------------------------------------------------------------------

    public function testNonBuiltinTypedParamFetchedFromContainer(): void
    {
        $service = new FakeService();
        $service->value = 'from-container';

        $container = new class($service) implements ContainerInterface {
            public function __construct(private readonly FakeService $svc) {}

            public function get(string $id): mixed
            {
                return $this->svc;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $invoker = new ContainerInvoker($container);
        $captured = null;
        $invoker->invoke(function (FakeService $svc) use (&$captured) {
            $captured = $svc->value;
        });
        self::assertSame('from-container', $captured);
    }

    // -------------------------------------------------------------------------
    // Return value is passed through
    // -------------------------------------------------------------------------

    public function testReturnsCallableReturnValue(): void
    {
        $invoker = new ContainerInvoker($this->emptyContainer());
        $result = $invoker->invoke(fn() => 99);
        self::assertSame(99, $result);
    }
}
