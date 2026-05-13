<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Exception;

use Dakujem\Migrun\MigrationFile;
use RuntimeException;

/**
 * Thrown when a migration file does not return a valid migration.
 */
class InvalidMigrationException extends RuntimeException
{
    public function __construct(MigrationFile $migration, mixed $returned = null)
    {
        $type = get_debug_type($returned);
        parent::__construct(
            "Migration file \"{$migration->id()}\" must return a callable or an object with a public up() method, got {$type}.",
        );
    }
}
