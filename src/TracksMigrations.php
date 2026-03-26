<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use DateTimeImmutable;

/**
 * Tracks which migrations have already been executed.
 */
interface TracksMigrations
{
    /**
     * Returns history entries of migrations that have already been applied,
     * most-recent first.
     *
     * Each entry carries the migration ID and the timestamp of when the
     * migration was run.
     *
     * @return iterable<MigrationHistoryEntry>
     */
    public function getApplied(): iterable;

    /**
     * Check whether a specific migration has ever been applied.
     *
     * Must consult the full history — must not be affected by any windowing or
     * filtering configured on the storage implementation. This prevents the
     * orchestrator from re-running already-executed migrations that fall outside
     * an active tracking window.
     */
    public function isApplied(string $id): bool;

    /**
     * Record a migration as successfully applied.
     *
     * Implementations should capture the current time as the run timestamp.
     */
    public function markApplied(string $id, ?DateTimeImmutable $at = null): void;

    /**
     * Remove a migration from the history (after a successful rollback).
     */
    public function markReverted(string $id): void;
}
