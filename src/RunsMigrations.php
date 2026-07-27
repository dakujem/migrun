<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * A migration runner: executes pending migrations, rolls them back, and reports status.
 *
 * Implemented by Orchestrator. Exposed as an interface so the runner can be
 * decorated transparently — e.g. to add mutual-exclusion locking, logging,
 * timing, or event emission — without callers depending on the concrete class.
 */
interface RunsMigrations
{
    /** @return MigrationRun[] The migrations that were executed. */
    public function run(): array;

    /** @return MigrationRun[] The migrations that were rolled back. */
    public function rollback(int $steps = 1): array;

    /** @return MigrationStatusEntry[] Status of all known migrations, ascending by ID. */
    public function status(): array;
}
