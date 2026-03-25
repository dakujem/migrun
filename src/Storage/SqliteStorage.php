<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Storage;

use Dakujem\Migrun\TracksMigrations;
use PDO;

/**
 * Tracks executed migrations in an SQLite database file.
 *
 * A convenience wrapper around PdoStorage that opens (and creates if needed)
 * an SQLite database at the given path, then delegates all tracking to a
 * PdoStorage instance.
 *
 * Usage:
 *   $storage = new SqliteStorage('/var/app/.migrun/history.sqlite');
 *
 * If you already have a PDO connection to an SQLite database you want to
 * reuse, construct a PdoStorage directly instead:
 *   $storage = new PdoStorage($existingPdo, 'migrun_migrations');
 */
final readonly class SqliteStorage implements TracksMigrations
{
    private PdoStorage $inner;

    public function __construct(
        string $filePath,
        string $table = PdoStorage::DefaultTableName,
    ) {
        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, recursive: true)) {
            throw new \RuntimeException("Could not create directory for SQLite migration storage: {$dir}");
        }

        $pdo = new PDO('sqlite:' . $filePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->inner = new PdoStorage($pdo, $table);
    }

    public function getApplied(): iterable
    {
        return $this->inner->getApplied();
    }

    public function isApplied(\Dakujem\Migrun\MigrationFile $migration): bool
    {
        return $this->inner->isApplied($migration);
    }

    public function markApplied(\Dakujem\Migrun\MigrationFile $migration, ?\DateTimeImmutable $at = null): void
    {
        $this->inner->markApplied($migration, $at);
    }

    public function markReverted(\Dakujem\Migrun\MigrationFile $migration, ?\DateTimeImmutable $at = null): void
    {
        $this->inner->markReverted($migration, $at);
    }
}
