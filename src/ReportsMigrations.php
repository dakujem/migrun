<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use Throwable;

/**
 * Observes a migration run or rollback as it happens, one migration at a time.
 *
 * The Orchestrator calls these methods around each individual migration, so a
 * CLI or web script can render live progress instead of waiting for the whole
 * batch to finish and inspecting the returned array.
 *
 * These calls are a side channel only: they never influence the run, and
 * implementations must not throw (a throwing reporter would abort the run).
 *
 * Lifecycle, per migration:
 *
 *   starting()              — about to execute the migration
 *   finished() | failed()   — exactly one of these follows
 *
 * On failure the Orchestrator re-throws immediately after calling failed(), so
 * the run still aborts on the first error exactly as before — the reporter just
 * gets to name the culprit first.
 *
 * The contract intentionally covers only the per-migration lifecycle. New events
 * can be introduced later without breaking existing reporters: declare them on a
 * separate interface that extends this one, and have the Orchestrator call the
 * new method only when the given reporter implements the extended interface (an
 * `instanceof` check). To stay forward-compatible, prefer extending NullReporter
 * over implementing this interface bare.
 *
 * @see NullReporter
 */
interface ReportsMigrations
{
    /**
     * A migration is about to be executed in the given direction.
     */
    public function starting(MigrationFile $file, Direction $direction): void;

    /**
     * A migration finished successfully.
     *
     * $run carries the migration and its measured execution duration.
     */
    public function finished(MigrationRun $run, Direction $direction): void;

    /**
     * A migration threw during execution.
     *
     * The Orchestrator re-throws $error immediately after this call, aborting
     * the run. The history is left untouched for the failed migration.
     */
    public function failed(MigrationFile $file, Direction $direction, Throwable $error): void;
}
