<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * A migration runner that accepts a per-call reporter.
 *
 * The reporting-aware counterpart to RunsMigrations: same contract, plus an optional
 * reporter on run() and rollback(). Type against it wherever a reporter needs to be
 * passed through an abstraction rather than the concrete Orchestrator.
 *
 * @see RunsMigrations
 * @see ReportsMigrations
 */
interface RunsMigrationsWithReporter extends RunsMigrations
{
    /**
     * @param ReportsMigrations|null $reporter Reporter for this call only; when null the
     *        implementation's own default (if any) is used.
     * @return MigrationRun[] The migrations that were executed.
     */
    public function run(?ReportsMigrations $reporter = null): array;

    /**
     * @param ReportsMigrations|null $reporter Reporter for this call only; when null the
     *        implementation's own default (if any) is used.
     * @return MigrationRun[] The migrations that were rolled back.
     */
    public function rollback(int $steps = 1, ?ReportsMigrations $reporter = null): array;
}
