<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Storage;

use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\TracksMigrations;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use LengthException;
use PDO;
use RuntimeException;

/**
 * Tracks executed migrations in a database table via PDO.
 *
 * The table is created automatically if it does not exist. The default table
 * name is "migrun_migrations" but it is configurable so that the history can
 * be stored alongside the data being migrated, in whatever schema you choose.
 *
 * Table schema (created automatically):
 *   id         VARCHAR(255) PRIMARY KEY  — stable migration identifier
 *   applied_at VARCHAR(32)  NOT NULL     — UTC ISO 8601 timestamp of when it was run
 *
 *  Migration IDs longer than MaximumIdLength characters are rejected
 *  both on write and on read to prevent silent truncation
 *  on databases with lax configuration.
 *
 * The table name must be a plain identifier: letters, digits, and underscores
 * only, starting with a letter or underscore. This keeps SQL portable across
 * MySQL, PostgreSQL, and SQLite without any dialect-specific quoting.
 *
 * Note that identifier escaping is intentionally not used to keep the implementation portable.
 */
final class PdoStorage implements TracksMigrations
{
    public const DefaultTableName = 'migrun_migrations';
    public const MaximumIdLength = 255;

    private bool $tableEnsured = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = self::DefaultTableName,
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

        $stmt = $this->pdo->query(
            "SELECT id, applied_at FROM {$this->table} ORDER BY applied_at DESC, id DESC",
        );
        if ($stmt === false) {
            throw new RuntimeException("Could not query migration storage table: {$this->table}");
        }

        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[] = $this->rowToEntry($row);
        }
        return $entries;
    }

    public function isApplied(string $id): bool
    {
        $this->assertIdLength($id);
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM {$this->table} WHERE id = ? LIMIT 1",
        );
        if ($stmt === false || !$stmt->execute([$id])) {
            throw new RuntimeException("Could not query migration storage table: {$this->table}");
        }

        return $stmt->fetchColumn() !== false;
    }

    public function markApplied(string $id, ?DateTimeInterface $at = null): void
    {
        $this->assertIdLength($id);
        $this->ensureTable();

        // Guard against duplicates: the primary key also enforces this at the
        // DB level, but we skip silently rather than propagating a DB error.
        if ($this->isApplied($id)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (id, applied_at) VALUES (?, ?)",
        );
        if ($stmt === false || !$stmt->execute([
                $id,
                ($at ?? new DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            ])) {
            throw new RuntimeException("Could not insert into migration storage table: {$this->table}");
        }
    }

    public function markReverted(string $id): void
    {
        $this->assertIdLength($id);
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE id = ?",
        );
        if ($stmt === false || !$stmt->execute([$id])) {
            throw new RuntimeException("Could not delete from migration storage table: {$this->table}");
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Create the tracking table if it does not already exist.
     *
     * Uses "CREATE TABLE IF NOT EXISTS" which is supported by SQLite,
     * MySQL/MariaDB, and PostgreSQL (9.1+).
     */
    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                id         VARCHAR(255) NOT NULL,
                applied_at VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id)
            )",
        );

        $this->tableEnsured = true;
    }

    /**
     * Deserialise one row from the database into a MigrationHistoryEntry.
     *
     * Expected keys: "id" (string), "applied_at" (UTC ISO 8601 string).
     */
    private function rowToEntry(mixed $row): MigrationHistoryEntry
    {
        if (is_array($row) && isset($row['id'], $row['applied_at'])) {
            $this->assertIdLength($row['id']);
            $at = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $row['applied_at'])
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

    private function assertIdLength(string $id): void
    {
        if (strlen($id) > self::MaximumIdLength) {
            throw new LengthException(
                "Migration ID is too long for storage (max " . self::MaximumIdLength . " bytes, got " . strlen($id) . "): \"{$id}\"",
            );
        }
    }
}
