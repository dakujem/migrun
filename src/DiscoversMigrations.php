<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use Dakujem\Migrun\Exception\MigrationNotFoundException;

/**
 * Discovers migration files available to be executed.
 *
 * Implementations are free to impose any filename convention they choose.
 * The built-in DirectoryFinder accepts any .php file and sorts by ID derived from the file paths
 * lexicographically; the recommended naming convention is a leading timestamp
 * prefix (e.g. 20240101_120000_create_users.php) so that lexicographic order and
 * chronological order coincide.
 * @see DirectoryFinder
 */
interface DiscoversMigrations
{
    /**
     * Returns all available migration files, sorted ascending by ID.
     *
     * @return iterable<MigrationFile>
     */
    public function list(): iterable;

    /**
     * Resolves a specific set of migrations by ID or MigrationHistoryEntry,
     * returning a map of ID => MigrationFile for each requested migration.
     *
     * @param array<string|MigrationHistoryEntry> $migrations IDs or history entries to resolve.
     * @return array<string, MigrationFile>                   Map of ID => MigrationFile, in the same order as $migrations.
     * @throws MigrationNotFoundException                     When any requested migration cannot be found.
     */
    public function find(array $migrations): array;
}
