<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Exception;

use RuntimeException;

/**
 * Thrown when a migration that is being rolled back cannot be found on disk.
 */
class MigrationNotFoundException extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(
            "Migration \"{$id}\" was recorded as run but could not be found on disk.",
        );
    }
}
