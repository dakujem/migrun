<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use Throwable;

/**
 * A reporter that does nothing.
 *
 * This is the default used by Orchestrator when no reporter is configured, so
 * the runner never has to null-check before reporting.
 *
 * @see ReportsMigrations
 */
class NullReporter implements ReportsMigrations
{
    public function starting(MigrationFile $file, Direction $direction): void
    {
    }

    public function finished(MigrationRun $run, Direction $direction): void
    {
    }

    public function failed(MigrationFile $file, Direction $direction, Throwable $error): void
    {
    }
}
