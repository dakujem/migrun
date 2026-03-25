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
 *   {"id": "20240101_120000_create_users", "at": "2024-01-01T12:00:00+00:00"},
 *   {"id": "20240115_090000_add_email_index", "at": "2024-01-15T09:00:00+00:00"}
 * ]
 *
 * The "at" timestamp records when the migration was executed, not when it
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
            at: $at ?? new DateTimeImmutable(), // current time
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
     * Expected format: {"id": "...", "at": "<ISO 8601>"}
     */
    private function rowToEntry(mixed $row): MigrationHistoryEntry
    {
        if (is_array($row) && isset($row['id'], $row['at'])) {
            $at = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $row['at'])
                ?: throw new RuntimeException("Migration storage file is corrupted or not valid JSON: {$this->filePath}");
            return new MigrationHistoryEntry(
                id: $row['id'],
                at: $at,
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
                'at' => $e->at()->format(DateTimeImmutable::ATOM),
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
