<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Storage;

use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\TracksMigrations;
use DateTimeImmutable;
use InvalidArgumentException;
use mysqli;
use RuntimeException;

/**
 * Tracks executed migrations in a MySQL/MariaDB table via mysqli.
 *
 * The table is created automatically if it does not exist. The default table
 * name is "migrun_migrations" but it is configurable so that the history can
 * be stored alongside the data being migrated, in whatever schema you choose.
 *
 * Table schema (created automatically):
 *   id         VARCHAR(255) PRIMARY KEY  — stable migration identifier
 *   applied_at TIMESTAMP    NOT NULL     — UTC datetime of when it was run
 *
 * The table name must be a plain identifier: letters, digits, and underscores
 * only, starting with a letter or underscore. Backtick quoting is used, which
 * is correct and unambiguous for MySQL/MariaDB.
 */
final class MysqliStorage implements TracksMigrations
{
    public const DEFAULT_TABLE = 'migrun_migrations';

    private bool $tableEnsured = false;

    public function __construct(
        private readonly mysqli $mysqli,
        private readonly string $table = self::DEFAULT_TABLE,
    ) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new InvalidArgumentException(
                "Invalid migration storage table name \"{$table}\". " .
                'Use only letters, digits, and underscores, starting with a letter or underscore.',
            );
        }
    }

    /**
     * @return iterable<MigrationHistoryEntry>
     */
    public function getApplied(): iterable
    {
        $this->ensureTable();

        $result = $this->mysqli->query(
            "SELECT id, applied_at FROM `{$this->table}` ORDER BY applied_at DESC, id DESC",
        );
        if ($result === false) {
            throw new RuntimeException("Could not query migration storage table: {$this->table}");
        }

        $entries = [];
        while ($row = $result->fetch_assoc()) {
            $entries[] = $this->rowToEntry($row);
        }
        $result->free();
        return $entries;
    }

    public function isApplied(MigrationFile $migration): bool
    {
        $this->ensureTable();

        $id = $this->mysqli->real_escape_string($migration->id());
        $result = $this->mysqli->query(
            "SELECT 1 FROM `{$this->table}` WHERE id = '{$id}' LIMIT 1",
        );
        if ($result === false) {
            throw new RuntimeException("Could not query migration storage table: {$this->table}");
        }

        $found = $result->num_rows > 0;
        $result->free();
        return $found;
    }

    public function markApplied(MigrationFile $migration, ?DateTimeImmutable $at = null): void
    {
        $this->ensureTable();

        if ($this->isApplied($migration)) {
            return;
        }

        $id = $this->mysqli->real_escape_string($migration->id());
        $appliedAt = $this->mysqli->real_escape_string(
            ($at ?? new DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );

        $ok = $this->mysqli->query(
            "INSERT INTO `{$this->table}` (id, applied_at) VALUES ('{$id}', '{$appliedAt}')",
        );
        if ($ok === false) {
            throw new RuntimeException("Could not insert into migration storage table: {$this->table}");
        }
    }

    public function markReverted(MigrationFile $migration, ?DateTimeImmutable $at = null): void
    {
        $this->ensureTable();

        $id = $this->mysqli->real_escape_string($migration->id());
        $ok = $this->mysqli->query(
            "DELETE FROM `{$this->table}` WHERE id = '{$id}'",
        );
        if ($ok === false) {
            throw new RuntimeException("Could not delete from migration storage table: {$this->table}");
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Create the tracking table if it does not already exist.
     */
    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $ok = $this->mysqli->query(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                id         VARCHAR(255) NOT NULL,
                applied_at TIMESTAMP    NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        );
        if ($ok === false) {
            throw new RuntimeException("Could not create migration storage table: {$this->table}");
        }

        $this->tableEnsured = true;
    }

    /**
     * Deserialise one row from the database into a MigrationHistoryEntry.
     *
     * Expected keys: "id" (string), "applied_at" (UTC datetime string 'Y-m-d H:i:s').
     */
    private function rowToEntry(mixed $row): MigrationHistoryEntry
    {
        if (is_array($row) && isset($row['id'], $row['applied_at'])) {
            $at = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['applied_at'], new \DateTimeZone('UTC'))
                ?: throw new RuntimeException(
                    "Migration storage table contains a corrupted timestamp for id={$row['id']}: {$row['applied_at']}",
                );
            return new MigrationHistoryEntry(
                id: $row['id'],
                at: $at,
            );
        }

        throw new RuntimeException('Migration storage table returned an unexpected row structure.');
    }
}
