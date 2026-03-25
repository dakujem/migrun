<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * A forward-only migration.
 *
 * Migration files may return an instance of this interface for one-way migrations.
 * To support rollback, return a {@see ReversibleMigration} instance instead.
 */
interface Migration
{
    public function up(): void;
}
