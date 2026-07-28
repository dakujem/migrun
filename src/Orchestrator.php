<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use Dakujem\Migrun\Exception\MigrationNotAppliedException;
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
 *   status():
 *     1. Collect all history entries from storage (keyed by ID).
 *     2. Collect all migration files from finder (keyed by ID).
 *     3. Merge both sets, sort ascending by ID.
 *     4. Yield one MigrationStatusEntry per ID:
 *        - present in both  → Applied  (appliedAt set, path set)
 *        - file only        → Pending  (appliedAt null, path set)
 *        - history only     → Missing  (appliedAt set, path null)
 *
 * The orchestrator owns ordering entirely. Migration IDs are always compared byte by
 * byte (strcmp), and both the finder's output and the storage's history are sorted here
 * rather than trusted as received — collaborators differ (SQL sorts by its own
 * collation, the JSON file by insertion order), so relying on them would let run order,
 * rollback order and status order drift apart.
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
        return $this->apply(null, $reporter);
    }

    /**
     * Execute pending migrations up to and including $id.
     *
     * Migrations ordered after $id are left pending; a later run() picks them up.
     * The named migration is itself executed (if still pending) — see rollbackBefore()
     * for the mirror image.
     *
     * @param string $id The last migration to apply.
     * @param ReportsMigrations|null $reporter Optional per-call reporter — see run().
     * @return MigrationRun[] The migrations that were executed.
     * @throws MigrationNotFoundException if $id cannot be found on disk.
     */
    public function runTo(string $id, ?ReportsMigrations $reporter = null): array
    {
        // Resolving the target validates it: an unknown ID must not silently run a
        // different set than the caller intended.
        $this->finder->find([$id]);

        return $this->apply(
            fn(MigrationFile $migration) => strcmp($migration->id(), $id) <= 0,
            $reporter,
        );
    }

    /**
     * Roll back the last $steps migrations — the most recently applied ones.
     *
     * "Last" means last *applied*, not highest ID. If a migration arrived out of order
     * (a branch merge, typically) it is the one reverted, even though its ID is lower.
     * That is what makes `rollback()` undo what was actually done last.
     *
     * @param ReportsMigrations|null $reporter Optional per-call reporter — see run().
     * @return MigrationRun[] The migrations that were rolled back.
     * @throws MigrationNotFoundException if a recorded migration cannot be found on disk.
     */
    public function rollback(int $steps = 1, ?ReportsMigrations $reporter = null): array
    {
        return $this->revert(
            array_slice($this->appliedIdsDescending(), 0, max($steps, 0)),
            $reporter,
        );
    }

    /**
     * Roll back everything applied from $id onwards, so that the resulting state
     * precedes $id.
     *
     * The named migration is itself reverted, together with every migration ordered
     * after it. Pick $id from a status listing and the reverted set is exactly "this
     * entry and everything below it" — the *set* is chosen by ID, using the same
     * byte-wise comparison status() lists by.
     *
     * The *sequence* of reversal, however, is reverse-application order (see
     * rollback()), which can differ from reverse-ID order when a migration was applied
     * out of order. Use rollbackExactly() to control the sequence yourself.
     *
     * @param string $id The oldest migration to revert.
     * @param ReportsMigrations|null $reporter Optional per-call reporter — see run().
     * @return MigrationRun[] The migrations that were rolled back.
     * @throws MigrationNotAppliedException if $id is not currently applied.
     * @throws MigrationNotFoundException if a recorded migration cannot be found on disk.
     */
    public function rollbackBefore(string $id, ?ReportsMigrations $reporter = null): array
    {
        // isApplied() consults the full history, so this holds even for a storage that
        // returns a windowed getApplied().
        if (!$this->storage->isApplied($id)) {
            throw new MigrationNotAppliedException($id);
        }

        return $this->revert(
            array_values(array_filter(
                $this->appliedIdsDescending(),
                fn(string $applied) => strcmp($applied, $id) >= 0,
            )),
            $reporter,
        );
    }

    /**
     * Roll back every applied migration, in reverse-application order.
     *
     * @param ReportsMigrations|null $reporter Optional per-call reporter — see run().
     * @return MigrationRun[] The migrations that were rolled back.
     * @throws MigrationNotFoundException if a recorded migration cannot be found on disk.
     */
    public function rollbackAll(?ReportsMigrations $reporter = null): array
    {
        return $this->revert($this->appliedIdsDescending(), $reporter);
    }

    /**
     * Reverts exactly the given migrations, in the given order. They need not be contiguous.
     *
     * Unlike the other rollback methods, this one imposes no ordering of its own — the
     * migrations are reverted in the order the array lists them. Note that a status
     * listing is ascending by ID, which is the *opposite* of a sensible rollback order,
     * so reverse it first:
     *
     *   $runner->rollbackExactly(array_reverse($ids));   // newest first
     *
     * This is the escape hatch for reverting a single migration out of the middle of the
     * history — a branch's migration, say, while newer ones from elsewhere stay applied.
     * Doing so deliberately leaves a gap: the migration becomes pending again and a later
     * run() will re-apply it, at its ID position and therefore after migrations with
     * higher IDs. Whether that is safe is the caller's judgement.
     *
     * Duplicate IDs are ignored.
     *
     * @param string[] $orderedIds Migrations to revert, in the order they should be reverted.
     * @param ReportsMigrations|null $reporter Optional per-call reporter — see run().
     * @return MigrationRun[] The migrations that were rolled back.
     * @throws MigrationNotAppliedException if any of the given migrations is not applied.
     * @throws MigrationNotFoundException if a recorded migration cannot be found on disk.
     */
    public function rollbackExactly(array $orderedIds, ?ReportsMigrations $reporter = null): array
    {
        $ids = array_values(array_unique($orderedIds));

        // Validate the whole set up front, so a bad ID cannot revert half the batch.
        foreach ($ids as $id) {
            if (!$this->storage->isApplied($id)) {
                throw new MigrationNotAppliedException($id);
            }
        }

        return $this->revert($ids, $reporter);
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
     * Execute every pending migration that passes $filter, in ascending ID order.
     *
     * The finder's own order is not trusted: ordering is decided in one place only, so a
     * finder implementation cannot put run order out of step with rollback or status order.
     *
     * @param null|callable(MigrationFile):bool $filter Null runs everything pending.
     * @return MigrationRun[]
     */
    private function apply(?callable $filter, ?ReportsMigrations $reporter): array
    {
        $reporter ??= $this->reporter;

        $pending = [];
        foreach ($this->finder->list() as $migration) {
            if ($this->storage->isApplied($migration->id())) {
                continue;
            }
            if ($filter !== null && !$filter($migration)) {
                continue;
            }
            $pending[] = $migration;
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
            $run = new MigrationRun($migration, $end - $start);
            $executed[] = $run;
            $reporter->finished($run, Direction::Up);
        }

        return $executed;
    }

    /**
     * Revert the given migrations, in the order given.
     *
     * Every rollback method funnels through here, having already decided *which*
     * migrations to revert and in *what order*.
     *
     * @param string[] $ids
     * @return MigrationRun[]
     */
    private function revert(array $ids, ?ReportsMigrations $reporter): array
    {
        $reporter ??= $this->reporter;

        // Resolve only the migrations we actually need, rather than listing everything.
        $available = $this->finder->find($ids);

        $reverted = [];
        foreach ($ids as $id) {
            $migration = $available[$id];

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
            $run = new MigrationRun($migration, $end - $start);
            $reverted[] = $run;
            $reporter->finished($run, Direction::Down);
        }

        return $reverted;
    }

    /**
     * IDs of the applied migrations, most-recently-applied first.
     *
     * @return string[]
     */
    private function appliedIdsDescending(): array
    {
        return array_map(
            fn(MigrationHistoryEntry $entry) => $entry->id(),
            $this->appliedDescending(),
        );
    }

    /**
     * The applied history, most-recently-applied first.
     *
     * Ordering is imposed here rather than taken from the storage: backends differ
     * (SQL sorts by its own collation, the JSON file by insertion order), so relying
     * on them would make rollback order depend on which storage is configured.
     *
     * Sorted by timestamp descending, then by ID descending — byte-wise, matching the finder.
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
