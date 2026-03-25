<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * Executes a single migration file in the given direction.
 */
interface ExecutesMigrations
{
    public function execute(MigrationFile $migration, Direction $direction): void;
}
