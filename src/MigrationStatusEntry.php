<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use DateTimeInterface;

/**
 * A single entry in the migration status report.
 *
 * Combines information from the filesystem (the file path) and the history
 * storage (the applied-at timestamp) into one record.
 *
 * - Applied: both $path and $appliedAt are set.
 * - Pending: $path is set, $appliedAt is null.
 * - Missing: $appliedAt is set, $path is null.
 */
final readonly class MigrationStatusEntry
{
    public function __construct(
        public string $id,
        public MigrationState $state,
        public ?DateTimeInterface $appliedAt,
        public ?string $path,
    ) {
    }
}
