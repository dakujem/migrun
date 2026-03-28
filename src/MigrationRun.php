<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * A single migration run with execution duration measurement.
 */
final readonly class MigrationRun
{
    public function __construct(
        public MigrationFile $file,
        public float $durationSeconds,
    ) {
    }

    public function id(): string
    {
        return $this->file->id();
    }
}
