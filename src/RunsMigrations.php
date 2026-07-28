<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * A migration runner: executes pending migrations, rolls them back, and reports status.
 *
 * Implemented by Orchestrator. Exposed as an interface so the runner can be
 * decorated transparently — e.g. to add mutual-exclusion locking, logging,
 * timing, or event emission — without callers depending on the concrete class.
 *
 * Two rules govern order throughout:
 *
 *   1. Migration IDs are compared byte by byte (strcmp) — never as numbers.
 *   2. Rollbacks proceed in reverse APPLICATION order, not reverse ID order. "Roll back
 *      the last one" therefore means the one applied most recently, which may not be the
 *      one with the highest ID if a migration arrived out of order (a branch merge, say).
 *      The sole exception is rollbackExactly(), which follows the order the caller gives.
 *
 * A decorator that wraps this interface must wrap EVERY mutating method. Note that
 * status() is read-only and may pass through unlocked, but all run* and rollback*
 * methods mutate.
 */
interface RunsMigrations
{
    /** @return MigrationRun[] The migrations that were executed. */
    public function run(): array;

    /**
     * Execute pending migrations up to and including $id.
     *
     * @return MigrationRun[] The migrations that were executed.
     */
    public function runTo(string $id): array;

    /**
     * Roll back the $steps most recently applied migrations.
     *
     * @return MigrationRun[] The migrations that were rolled back.
     */
    public function rollback(int $steps = 1): array;

    /**
     * Roll back $id and everything applied after it, leaving the state before $id.
     *
     * @return MigrationRun[] The migrations that were rolled back.
     */
    public function rollbackBefore(string $id): array;

    /**
     * Roll back every applied migration.
     *
     * @return MigrationRun[] The migrations that were rolled back.
     */
    public function rollbackAll(): array;

    /**
     * Revert exactly the given migrations, in the given order. They need not be contiguous.
     *
     * @param string[] $orderedIds Migrations to revert, in the order they should be reverted.
     * @return MigrationRun[] The migrations that were rolled back.
     */
    public function rollbackExactly(array $orderedIds): array;

    /** @return MigrationStatusEntry[] Status of all known migrations, ascending by ID. */
    public function status(): array;
}
