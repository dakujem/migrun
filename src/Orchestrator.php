<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use Dakujem\Migrun\Exception\MigrationNotFoundException;

/**
 * Orchestrates the full migration run or rollback cycle:
 *
 *   run():
 *     1. Ask storage for the list of already-ran migration IDs.
 *     2. Ask finder for all available migrations (sorted ascending).
 *     3. Filter to pending migrations (not yet ran).
 *     4. Execute each pending migration via the executor.
 *     5. Record each successful execution in storage immediately.
 *
 *   rollback(int $steps):
 *     1. Ask storage for applied migrations (most-recent-first order).
 *     2. Ask finder for all available migrations.
 *     3. For each of the last $steps applied migrations, execute Down and remove from storage.
 *
 *   status():
 *     1. Collect all history entries from storage (keyed by ID).
 *     2. Collect all migration files from finder (keyed by ID).
 *     3. Merge both sets, sort ascending by ID.
 *     4. Yield one MigrationStatusEntry per ID:
 *        - present in both  → Applied  (appliedAt set, path set)
 *        - file only        → Pending  (appliedAt null, path set)
 *        - history only     → Missing  (appliedAt set, path null)
 */
final readonly class Orchestrator
{
    public function __construct(
        private TracksMigrations $storage,
        private DiscoversMigrations $finder,
        private ExecutesMigrations $executor,
    ) {
    }

    /**
     * Execute all pending migrations.
     *
     * @return MigrationFile[] The migrations that were executed.
     */
    public function run(): array
    {
        $all = $this->finder->list();

        $executed = [];
        foreach ($all as $migration) {
            if ($this->storage->isApplied($migration->id())) {
                continue;
            }
            $this->executor->execute($migration, Direction::Up);
            $this->storage->markApplied($migration->id());
            $executed[] = $migration;
        }

        return $executed;
    }

    /**
     * Roll back the last $steps migrations.
     *
     * @return MigrationFile[] The migrations that were rolled back.
     * @throws MigrationNotFoundException if a recorded migration cannot be found on disk.
     */
    public function rollback(int $steps = 1): array
    {
        // Storage returns most-recent-first; collect only the first $steps entries.
        $targets = [];
        foreach ($this->storage->getApplied() as $entry) {
            if (count($targets) >= $steps) {
                break;
            }
            $targets[] = $entry;
        }

        // Resolve only the migrations we actually need, rather than listing everything
        $available = $this->finder->find($targets);

        $reverted = [];
        foreach ($targets as $entry) {
            $migration = $available[$entry->id()];
            $this->executor->execute($migration, Direction::Down);
            $this->storage->markReverted($migration->id());
            $reverted[] = $migration;
        }

        return $reverted;
    }

    /**
     * Return the status of all known migrations, ordered ascending by ID.
     *
     * Each entry carries:
     *   - Applied  — recorded in history and the file is present on disk
     *   - Pending  — file is present on disk but has not been run yet
     *   - Missing  — recorded in history but the file no longer exists on disk
     *
     * @return iterable<MigrationStatusEntry>
     */
    public function status(): iterable
    {
        // Collect history: id => MigrationHistoryEntry
        $history = [];
        foreach ($this->storage->getApplied() as $entry) {
            $history[$entry->id()] = $entry;
        }

        // Collect files: id => MigrationFile
        $files = [];
        foreach ($this->finder->list() as $migration) {
            $files[$migration->id()] = $migration;
        }

        // Merge all known IDs and sort ascending
        $ids = array_keys($history + $files);
        sort($ids);

        $entries = [];
        foreach ($ids as $id) {
            $inHistory = isset($history[$id]);
            $onDisk    = isset($files[$id]);

            $entries[] = new MigrationStatusEntry(
                id:        $id,
                state:     match (true) {
                    $inHistory && $onDisk => MigrationState::Applied,
                    $onDisk              => MigrationState::Pending,
                    default              => MigrationState::Missing,
                },
                appliedAt: $inHistory ? $history[$id]->at() : null,
                path:      $onDisk    ? $files[$id]->path() : null,
            );
        }

        return $entries;
    }
}
