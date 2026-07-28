<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Finder;

use Dakujem\Migrun\DiscoversMigrations;
use Dakujem\Migrun\Exception\MigrationNotFoundException;
use Dakujem\Migrun\LexicographicOrder;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\OrdersMigrations;
use FilesystemIterator;
use Iterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Discovers migration files in a configured directory.
 *
 * Every .php file found in the directory is treated as a migration — no
 * filename format is enforced. The recommended convention is a leading
 * timestamp prefix so that lexicographic and chronological order coincide:
 *
 *   YYYYMMDD_HHMMSS_<name>.php   e.g.  20240101_120000_create_users.php
 *
 * The migration ID is derived by stripping the base directory prefix and the
 * .php extension from the absolute path. For a flat directory this yields just
 * the filename stem (e.g. "20240101_120000_create_users"); for recursive scans
 * it includes the relative subdirectory path using the system directory
 * separator (e.g. "2024/01/20240101_120000_create_users").
 *
 * Results are sorted ascending by ID using the injected OrdersMigrations
 * comparison — by default LexicographicOrder, i.e. a byte-by-byte comparison.
 * With the recommended timestamp-prefixed naming, this naturally produces
 * chronological order. The finder never parses or interprets the filename.
 *
 * Note that byte-wise, '10' sorts BEFORE '9'. Zero-pad numeric IDs to a fixed
 * width (001, 002, … 010) so that byte order matches numeric order.
 *
 * When $recursive is true, subdirectories are scanned as well. Sorting still
 * produces a single globally-ordered list across all subdirectories.
 */
final readonly class DirectoryFinder implements DiscoversMigrations
{
    public function __construct(
        private string $directory,
        private bool $recursive = false,
        private OrdersMigrations $order = new LexicographicOrder(),
    ) {
    }

    public function list(): iterable
    {
        $scan = $this->scan();
        $migrations = !is_array($scan) ? iterator_to_array($scan) : $scan;

        usort(
            $migrations,
            fn(MigrationFile $a, MigrationFile $b) => $this->order->compare($a->id(), $b->id()),
        );

        return $migrations;
    }

    public function find(array $migrations): array
    {
        // Scan the directory once, index by ID
        //
        // Note:
        //   It should be possible to optimize this algorithm so that it is unnecessary to index the whole directory
        //   when looking for a single file by leveraging the nature of the generator returned by the scan method.
        //   On the other hand, the files being searched for will usually be the last ones in the list,
        //   thus the optimization might not be worth it.
        $scan = $this->scan();
        $available = [];
        foreach ($scan as $file) {
            $available[$file->id()] = $file;
        }

        // Resolve each requested ID in order, preserving input order in the result
        $result = [];
        foreach ($migrations as $migration) {
            $id = $migration instanceof MigrationHistoryEntry ? $migration->id() : $migration;
            if (!isset($available[$id])) {
                throw new MigrationNotFoundException($id);
            }
            $result[$id] = $available[$id];
        }

        return $result;
    }

    /**
     * Scan the directory and return all .php MigrationFile instances.
     * No sorting applied — callers sort as needed.
     *
     * @return MigrationFile[]
     */
    private function scan(): iterable
    {
        if (!is_dir($this->directory)) {
            throw new RuntimeException("Migration directory does not exist: {$this->directory}");
        }

        // Normalise the base directory path: strip any trailing separator so that
        // the prefix we remove is consistent regardless of how the path was supplied.
        $base = rtrim($this->directory, '/\\');

        /** @var SplFileInfo $file */
        foreach ($this->createIterator() as $file) {
            if (
                !$file->isFile()
                || $file->getExtension() !== 'php' // We only support .php files at the moment
            ) {
                continue;
            }

            $absolutePath = $file->getPathname();

            // Derive the ID by removing the base directory prefix and the .php extension.
            // The resulting ID is a relative path stem, e.g.:
            //   base:  /migrations
            //   path:  /migrations/2024/01/20240101_create_users.php
            //   id:    2024/01/20240101_create_users
            $relative = ltrim(substr($absolutePath, strlen($base)), '/\\');
            $id = substr($relative, 0, -4); // strip ".php"

            yield new MigrationFile(
                path: $absolutePath,
                id: $id,
            );
        }
    }

    private function createIterator(): Iterator
    {
        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO;

        if (!$this->recursive) {
            return new FilesystemIterator($this->directory, $flags);
        }

        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, $flags),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
    }
}
