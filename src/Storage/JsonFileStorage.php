<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Storage;

use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\TracksMigrations;
use DateTimeImmutable;
use RuntimeException;

/**
 * Tracks executed migrations in a JSON file on disk.
 *
 * File format — a JSON array of entry objects in execution order:
 * [
 *   {"id": "20240101_120000_create_users", "ran_at": "2024-01-01T12:00:00+00:00"},
 *   {"id": "20240115_090000_add_email_index", "ran_at": "2024-01-15T09:00:00+00:00"}
 * ]
 *
 * The "ran_at" timestamp records when the migration was executed, not when it
 * was created.
 *
 * This is intentionally human-readable and VCS-friendly.
 */
final class JsonFileStorage implements TracksMigrations
{
    /** @var MigrationHistoryEntry[]|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $filePath,
    ) {
    }

    /**
     * @return iterable<MigrationHistoryEntry>
     */
    public function getApplied(): iterable
    {
        return array_reverse($this->readAll());
    }

    public function isApplied(MigrationFile $migration): bool
    {
        foreach ($this->readAll() as $entry) {
            if ($entry->id() === $migration->id()) {
                return true;
            }
        }
        return false;
    }

    public function markApplied(MigrationFile $migration, ?DateTimeImmutable $at = null): void
    {
        $all = $this->readAll();
        foreach ($all as $entry) {
            if ($entry->id() === $migration->id()) {
                return; // already present, no duplicates
            }
        }
        $all[] = new MigrationHistoryEntry(
            id: $migration->id(),
            ranAt: $at ?? new DateTimeImmutable(), // current time
        );
        $this->persist($all);
    }

    public function markReverted(MigrationFile $migration, ?DateTimeImmutable $at = null): void
    {
        $all = $this->readAll();
        $all = array_values(array_filter($all, fn(MigrationHistoryEntry $e) => $e->id() !== $migration->id()));
        $this->persist($all);
    }

    // -------------------------------------------------------------------------

    /**
     * Read the full list of applied migrations from disk without any filtering.
     *
     * @return MigrationHistoryEntry[]
     */
    private function readAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (!file_exists($this->filePath)) {
            return $this->cache = [];
        }

        $contents = file_get_contents($this->filePath);
        if ($contents === false) {
            throw new RuntimeException("Could not read migration storage file: {$this->filePath}");
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Migration storage file is corrupted or not valid JSON: {$this->filePath}");
        }

        $entries = [];
        foreach ($decoded as $row) {
            $entries[] = $this->rowToEntry($row);
        }
        return $this->cache = $entries;
    }

    /**
     * Deserialise one row from the JSON file into a MigrationHistoryEntry.
     *
     * Accepts:
     *   - New format:    {"id": "...", "ran_at": "<ISO 8601>"}
     *   - Legacy format: {"id": "...", "timestamp": "Ymd_His"}
     *   - Plain string:  "20240101_120000_create_users"
     */
    private function rowToEntry(mixed $row): MigrationHistoryEntry
    {
        // New format: {"id": "...", "ran_at": "..."}
        if (is_array($row) && isset($row['id'], $row['ran_at'])) {
            $ranAt = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $row['ran_at'])
                ?: new DateTimeImmutable('@0');
            return new MigrationHistoryEntry(
                id: $row['id'],
                ranAt: $ranAt,
            );
        }

        // Legacy format: {"id": "...", "timestamp": "Ymd_His"}
        if (is_array($row) && isset($row['id'], $row['timestamp'])) {
            $ranAt = DateTimeImmutable::createFromFormat('Ymd_His', $row['timestamp'])
                ?: new DateTimeImmutable('@0');
            return new MigrationHistoryEntry(
                id: $row['id'],
                ranAt: $ranAt,
            );
        }

        // Legacy plain string ID "20240101_120000_create_users"
        if (is_string($row)) {
            return new MigrationHistoryEntry(
                id: $row,
                ranAt: new DateTimeImmutable('@0'),
            );
        }

        throw new RuntimeException("Migration storage file is corrupted or not valid JSON: {$this->filePath}");
    }

    /**
     * @param MigrationHistoryEntry[] $entries
     */
    private function persist(array $entries): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, recursive: true)) {
                throw new RuntimeException("Could not create directory for migration storage: {$dir}");
            }
        }

        $rows = array_map(
            fn(MigrationHistoryEntry $e) => [
                'id' => $e->id(),
                'ran_at' => $e->ranAt()->format(DateTimeImmutable::ATOM),
            ],
            $entries,
        );

        $result = file_put_contents(
            $this->filePath,
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        if ($result === false) {
            throw new RuntimeException("Could not write migration storage file: {$this->filePath}");
        }

        $this->cache = null;
    }
}
