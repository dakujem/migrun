<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use Dakujem\Migrun\Exception\MigrationNotFoundException;
use Throwable;

/**
 * Orchestrates the full migration run or rollback cycle:
 *
 *   run():
 *     1. Ask finder for all available migrations.
 *     2. Filter to pending migrations (not yet ran), asking storage for each.
 *     3. Order them ascending by ID.
 *     4. Execute each pending migration via the executor.
 *     5. Record each successful execution in storage immediately.
 *
 *   rollback(int $steps):
 *     1. Ask storage for applied migrations and order them most-recent-first here.
 *     2. Ask finder for all available migrations.
 *     3. For each of the last $steps applied migrations, execute Down and remove from storage.
 *
 * The orchestrator owns ordering entirely. Migration IDs are always compared byte by
 * byte (strcmp), and both the finder's output and the storage's history are sorted here
 * rather than trusted as received — collaborators differ (SQL sorts by its own
 * collation, the JSON file by insertion order), so relying on them would let run order,
 * rollback order and status order drift apart.
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
final readonly class Orchestrator implements RunsMigrationsWithReporter
{
    public function __construct(
        private TracksMigrations $storage,
        private DiscoversMigrations $finder,
        private ExecutesMigrations $executor,
        private ReportsMigrations $reporter = new NullReporter(),
    ) {
    }

    /**
     * Execute all pending migrations.
     *
     * @param ReportsMigrations|null $reporter Optional reporter used for this call only,
     *        overriding the one given to the constructor. Handy when the reporter depends on
     *        per-invocation state (e.g. a console OutputInterface), so the runner itself can
     *        still be a plain, reusable service. When null, the constructor's reporter
     *        (a NullReporter by default) is used.
     * @return MigrationRun[] The migrations that were executed.
     */
    public function run(?ReportsMigrations $reporter = null): array
    {
        $reporter ??= $this->reporter;

        // Collect the pending set and order it here. The finder's own order is not
        // trusted: ordering is decided in one place only, so a finder implementation
        // cannot put run order out of step with rollback and status order.
        $pending = [];
        foreach ($this->finder->list() as $migration) {
            if (!$this->storage->isApplied($migration->id())) {
                $pending[] = $migration;
            }
        }
        usort($pending, fn(MigrationFile $a, MigrationFile $b) => strcmp($a->id(), $b->id()));

        $executed = [];
        foreach ($pending as $migration) {
            $reporter->starting($migration, Direction::Up);
            try {
                $start = microtime(true);
                $this->executor->execute($migration, Direction::Up);
                $end = microtime(true);
            } catch (Throwable $e) {
                $reporter->failed($migration, Direction::Up, $e);
                throw $e;
            }

            $this->storage->markApplied($migration->id());
            $run = new MigrationRun(
                $migration,
                $end - $start,
            );
            $executed[] = $run;
            $reporter->finished($run, Direction::Up);
        }

        return $executed;
    }

    /**
     * Roll back the last $steps migrations.
     *
     * @param ReportsMigrations|null $reporter Optional per-call reporter — see run().
     * @return MigrationRun[] The migrations that were rolled back.
     * @throws MigrationNotFoundException if a recorded migration cannot be found on disk.
     */
    public function rollback(int $steps = 1, ?ReportsMigrations $reporter = null): array
    {
        $reporter ??= $this->reporter;

        // Order the history most-recent-first here, then take the first $steps entries.
        $targets = array_slice($this->appliedDescending(), 0, max($steps, 0));

        // Resolve only the migrations we actually need, rather than listing everything
        $available = $this->finder->find($targets);

        $reverted = [];
        foreach ($targets as $entry) {
            $migration = $available[$entry->id()];

            $reporter->starting($migration, Direction::Down);
            try {
                $start = microtime(true);
                $this->executor->execute($migration, Direction::Down);
                $end = microtime(true);
            } catch (Throwable $e) {
                $reporter->failed($migration, Direction::Down, $e);
                throw $e;
            }

            $this->storage->markReverted($migration->id());
            $run = new MigrationRun(
                $migration,
                $end - $start,
            );
            $reverted[] = $run;
            $reporter->finished($run, Direction::Down);
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
     * @return array<MigrationStatusEntry>
     */
    public function status(): array
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

        // Merge all known IDs and sort ascending.
        // array_keys() casts numeric-string IDs (e.g. "9") to int, so cast them back
        // before comparing — MigrationStatusEntry::$id is a string.
        $ids = array_map('strval', array_keys($history + $files));
        usort($ids, fn(string $a, string $b) => strcmp($a, $b));

        $entries = [];
        foreach ($ids as $id) {
            $inHistory = isset($history[$id]);
            $onDisk = isset($files[$id]);

            $entries[] = new MigrationStatusEntry(
                id: $id,
                state: match (true) {
                    $inHistory && $onDisk => MigrationState::Applied,
                    $onDisk => MigrationState::Pending,
                    default => MigrationState::Missing,
                },
                appliedAt: $inHistory ? $history[$id]->at() : null,
                path: $onDisk ? $files[$id]->path() : null,
            );
        }

        return $entries;
    }

    // -------------------------------------------------------------------------

    /**
     * The applied history, most-recently-applied first.
     *
     * Ordering is imposed here rather than taken from the storage: backends differ
     * (SQL sorts by its own collation, the JSON file by insertion order), so relying
     * on them would make rollback order depend on which storage is configured.
     *
     * Sorted by timestamp descending, then by ID descending — byte-wise, matching the
     * finder. The ID is a genuine tiebreaker rather than a formality: timestamps are
     * recorded with one-second precision, so a single run() typically stamps several
     * migrations identically.
     *
     * @return MigrationHistoryEntry[]
     */
    private function appliedDescending(): array
    {
        $entries = [];
        foreach ($this->storage->getApplied() as $entry) {
            $entries[] = $entry;
        }

        usort(
            $entries,
            fn(MigrationHistoryEntry $a, MigrationHistoryEntry $b) => ($b->at() <=> $a->at())
                ?: strcmp($b->id(), $a->id()),
        );

        return $entries;
    }
}
