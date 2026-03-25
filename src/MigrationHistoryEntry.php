<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use DateTimeImmutable;

/**
 * A record of a migration that has been executed.
 *
 * Carries the migration ID and the timestamp of when the migration was run.
 * The timestamp is set at execution time — it is NOT derived from the filename.
 *
 * Storage implementations construct and return instances of this class to
 * represent history without needing access to the filesystem.
 */
final readonly class MigrationHistoryEntry
{
    public function __construct(
        private string $id,
        private DateTimeImmutable $ranAt,
    ) {
    }

    /** The stable migration identifier (e.g. derived from the filename by the finder). */
    public function id(): string
    {
        return $this->id;
    }

    /** The timestamp of when the migration was run. */
    public function ranAt(): DateTimeImmutable
    {
        return $this->ranAt;
    }
}
