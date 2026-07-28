<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use Dakujem\Migrun\Exception\MigrationNotFoundException;

/**
 * Discovers migration files available to be executed.
 *
 * Implementations are free to impose any filename convention they choose.
 * The built-in DirectoryFinder accepts any .php file and derives the ID from the file path;
 * the recommended naming convention is a leading timestamp prefix
 * (e.g. 20240101_120000_create_users.php) so that lexicographic order and
 * chronological order coincide.
 * @see DirectoryFinder
 */
interface DiscoversMigrations
{
    /**
     * Returns all available migration files.
     *
     * The order is NOT significant — the orchestrator sorts the result itself, so that
     * run order cannot drift out of step with rollback and status order. Implementations
     * may return files in any order.
     *
     * Implementations that do sort (as DirectoryFinder does, for the benefit of callers
     * using the finder directly) should compare IDs byte by byte — strcmp(), the same
     * rule as `LC_ALL=C sort`, which is what the orchestrator applies. Do NOT use PHP's
     * <=> operator: it compares two numeric strings as numbers, which is neither a total
     * order ('9' and '09' compare equal) nor transitive once numeric and non-numeric IDs
     * are mixed — either of which leaves the sort order undefined.
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
