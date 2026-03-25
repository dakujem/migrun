<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * Represents a single migration file on disk.
 *
 * Carries the absolute path and the stable ID derived by the finder that
 * produced this instance.
 *
 * There is no assumption about the filename format — all parsing is the
 * responsibility of the finder implementation.
 */
final readonly class MigrationFile
{
    public function __construct(
        private string $path,
        private string $id,
    ) {
    }

    /** Absolute path to the migration file on disk. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Stable identifier for this migration.
     *
     * Derived from the file path by the finder (e.g. by stripping the base
     * directory prefix and the .php extension). Callers must not assume any
     * particular format.
     */
    public function id(): string
    {
        return $this->id;
    }
}
