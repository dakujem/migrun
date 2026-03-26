<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * The state of a single migration in the status report.
 *
 * Applied — recorded in history and the file exists on disk.
 * Pending — file exists on disk but has not been run yet.
 * Missing — recorded in history but the file no longer exists on disk.
 */
enum MigrationState
{
    case Applied;
    case Pending;
    case Missing;
}
