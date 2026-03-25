<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Executor;

use Dakujem\Migrun\Direction;
use Dakujem\Migrun\Exception\InvalidMigrationException;
use Dakujem\Migrun\Exception\NoRollbackException;
use Dakujem\Migrun\ExecutesMigrations;
use Dakujem\Migrun\Migration;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\ReversibleMigration;

/**
 * Loads and executes migration files.
 *
 * A migration file must return one of:
 *   - a callable (supports Direction::Up only; invoked via the injected invoker)
 *   - a Migration instance (supports Direction::Up only)
 *   - a ReversibleMigration instance (supports both Up and Down)
 *
 * If the file returns anything else, an InvalidMigrationException is thrown.
 *
 * All callables — including the up() and down() methods of Migration instances —
 * are invoked through the injected InvokesCallable implementation (e.g.
 * ContainerInvoker). Concrete implementations may therefore declare typed
 * parameters on up()/down() beyond the parameter-less interface signature;
 * the invoker will resolve and supply them automatically.
 */
final readonly class Executor implements ExecutesMigrations
{
    public function __construct(
        private InvokesCallable $invoker,
    ) {
    }

    public function execute(MigrationFile $migration, Direction $direction): void
    {
        $result = require $migration->path();

        match ($direction) {
            Direction::Up => match (true) {
                $result instanceof Migration => $this->invoker->invoke($result->up(...)),
                is_callable($result) => $this->invoker->invoke($result),
                default => throw new InvalidMigrationException($migration, $result),
            },

            Direction::Down => match (true) {
                $result instanceof ReversibleMigration => $this->invoker->invoke($result->down(...)),
                $result instanceof Migration => throw new NoRollbackException($migration),
                is_callable($result) => throw new NoRollbackException($migration),
                default => throw new InvalidMigrationException($migration, $result),
            },
        };
    }
}
