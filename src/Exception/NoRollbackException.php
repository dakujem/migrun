<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Exception;

use Dakujem\Migrun\MigrationFile;
use LogicException;

/**
 * Thrown when a rollback is attempted on a migration that does not support it.
 *
 * To support rollback, the migration file must return a {@see ReversibleMigration} instance.
 */
class NoRollbackException extends LogicException
{
    public function __construct(MigrationFile $migration)
    {
        parent::__construct(
            "Migration \"{$migration->id()}\" does not support rollback. "
            . "Return a ReversibleInterface instance to enable down().",
        );
    }
}
