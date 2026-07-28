<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Exception;

use RuntimeException;

/**
 * Thrown when a rollback names a migration that is not currently applied.
 *
 * Targeted rollbacks name a specific migration, so silently doing nothing would hide a
 * typo or a stale listing. This is raised before anything is reverted.
 */
class MigrationNotAppliedException extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(
            "Migration \"{$id}\" cannot be rolled back because it is not currently applied.",
        );
    }
}
