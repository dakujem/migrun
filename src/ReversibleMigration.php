<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * A migration that supports both forward and rollback directions.
 *
 * Migration files may return an instance of this interface to support rollback.
 * Files returning a plain callable or a {@see Migration} instance
 * without this interface only support the "up" direction.
 */
interface ReversibleMigration extends Migration
{
    public function down(): void;
}
