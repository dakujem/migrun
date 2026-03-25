<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use DateTimeImmutable;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\Storage\JsonFileStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileStorageTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/migrun_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->storagePath)) {
            unlink($this->storagePath);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function migrationFile(string $id, ?string $name = null): MigrationFile
    {
        return new MigrationFile(path: "/migrations/{$id}.php", id: $id, name: $name);
    }

    /** Extract IDs from getApplied() for assertions that only care about ordering. */
    private function appliedIds(JsonFileStorage $storage): array
    {
        return array_map(fn(MigrationHistoryEntry $e) => $e->id(), iterator_to_array($storage->getApplied()));
    }

    // -------------------------------------------------------------------------
    // Basic persistence
    // -------------------------------------------------------------------------

    public function testReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        $storage = new JsonFileStorage($this->storagePath);
        self::assertSame([], iterator_to_array($storage->getApplied()));
    }

    public function testMarkAppliedPersistsEntry(): void
    {
        $storage = new JsonFileStorage($this->storagePath);
        $migration = $this->migrationFile('20240101_120000_create_users', 'create_users');
        $at = new DateTimeImmutable('2024-06-15T10:30:00+00:00');

        $storage->markApplied($migration, $at);

        $applied = iterator_to_array($storage->getApplied());
        self::assertCount(1, $applied);
        self::assertInstanceOf(MigrationHistoryEntry::class, $applied[0]);
        self::assertSame('20240101_120000_create_users', $applied[0]->id());
        self::assertSame('create_users', $applied[0]->name());
        self::assertEquals($at, $applied[0]->ranAt());
    }

    public function testMarkAppliedDoesNotDuplicate(): void
    {
        $storage = new JsonFileStorage($this->storagePath);
        $migration = $this->migrationFile('20240101_120000_create_users');

        $storage->markApplied($migration);
        $storage->markApplied($migration);

        self::assertCount(1, $storage->getApplied());
    }

    public function testMarkRevertedRemovesEntry(): void
    {
        $storage = new JsonFileStorage($this->storagePath);
        $m1 = $this->migrationFile('20240101_120000_create_users');
        $m2 = $this->migrationFile('20240115_090000_add_index');

        $storage->markApplied($m1);
        $storage->markApplied($m2);
        $storage->markReverted($m1);

        self::assertSame(['20240115_090000_add_index'], $this->appliedIds($storage));
    }

    public function testReturnsAppliedMostRecentFirst(): void
    {
        $storage = new JsonFileStorage($this->storagePath);
        $m1 = $this->migrationFile('20240101_120000_alpha');
        $m2 = $this->migrationFile('20240115_090000_beta');
        $m3 = $this->migrationFile('20240120_080000_gamma');

        $storage->markApplied($m1);
        $storage->markApplied($m2);
        $storage->markApplied($m3);

        self::assertSame([
            '20240120_080000_gamma',
            '20240115_090000_beta',
            '20240101_120000_alpha',
        ], $this->appliedIds($storage));
    }

    public function testThrowsOnCorruptedFile(): void
    {
        file_put_contents($this->storagePath, 'not json at all');
        $storage = new JsonFileStorage($this->storagePath);

        $this->expectException(RuntimeException::class);
        $storage->getApplied();
    }

    // -------------------------------------------------------------------------
    // isApplied
    // -------------------------------------------------------------------------

    public function testIsAppliedReturnsTrueForAppliedMigration(): void
    {
        $storage = new JsonFileStorage($this->storagePath);
        $m = $this->migrationFile('20240101_120000_create_users');
        $storage->markApplied($m);

        self::assertTrue($storage->isApplied($m));
    }

    public function testIsAppliedReturnsFalseForUnknownId(): void
    {
        $storage = new JsonFileStorage($this->storagePath);
        $m = $this->migrationFile('20240101_120000_create_users');

        self::assertFalse($storage->isApplied($m));
    }

    public function testIsAppliedReturnsFalseWhenFileDoesNotExist(): void
    {
        $storage = new JsonFileStorage($this->storagePath);
        $m = $this->migrationFile('20240101_120000_anything');

        self::assertFalse($storage->isApplied($m));
    }

    // -------------------------------------------------------------------------
    // Legacy file formats — backward compatibility
    // -------------------------------------------------------------------------

    public function testReadsNewFormatWithRanAt(): void
    {
        file_put_contents(
            $this->storagePath,
            json_encode([
                ['id' => '20240101_120000_create_users', 'name' => 'create_users', 'ran_at' => '2024-01-01T12:00:00+00:00'],
            ], JSON_PRETTY_PRINT) . "\n",
        );

        $storage = new JsonFileStorage($this->storagePath);
        $applied = iterator_to_array($storage->getApplied());

        self::assertCount(1, $applied);
        self::assertSame('20240101_120000_create_users', $applied[0]->id());
        self::assertSame('create_users', $applied[0]->name());
        self::assertEquals(
            new DateTimeImmutable('2024-01-01T12:00:00+00:00'),
            $applied[0]->ranAt(),
        );
    }

    public function testReadsLegacyFormatWithTimestamp(): void
    {
        // Old format used "timestamp" in Ymd_His format
        file_put_contents(
            $this->storagePath,
            json_encode([
                ['id' => '20240101_120000_create_users', 'name' => 'create_users', 'timestamp' => '20240101_120000'],
                ['id' => '20240115_090000_add_index', 'name' => 'add_index', 'timestamp' => '20240115_090000'],
            ], JSON_PRETTY_PRINT) . "\n",
        );

        $storage = new JsonFileStorage($this->storagePath);
        $applied = iterator_to_array($storage->getApplied());

        self::assertCount(2, $applied);
        // most-recent-first
        self::assertSame('20240115_090000_add_index', $applied[0]->id());
        self::assertSame('20240101_120000_create_users', $applied[1]->id());
    }

    public function testReadsLegacyPlainStringIds(): void
    {
        // Oldest format: plain array of string IDs
        file_put_contents(
            $this->storagePath,
            json_encode([
                '20240101_120000_create_users',
                '20240115_090000_add_index',
            ], JSON_PRETTY_PRINT) . "\n",
        );

        $storage = new JsonFileStorage($this->storagePath);
        $applied = iterator_to_array($storage->getApplied());

        self::assertCount(2, $applied);
        // most-recent-first (storage order reversed)
        self::assertSame('20240115_090000_add_index', $applied[0]->id());
        self::assertSame('20240101_120000_create_users', $applied[1]->id());
        // name is null for plain-string legacy entries
        self::assertNull($applied[0]->name());
    }
}
