<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use DateTimeImmutable;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\Storage\PdoStorage;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests PdoStorage against an in-memory SQLite database so that the suite
 * requires no external infrastructure.
 */
final class PdoStorageTest extends TestCase
{
    private PDO $pdo;
    private PdoStorage $storage;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->storage = new PdoStorage($this->pdo);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function migrationFile(string $id): MigrationFile
    {
        return new MigrationFile(path: "/migrations/{$id}.php", id: $id);
    }

    /** Extract IDs from getApplied() for assertions that only care about ordering. */
    private function appliedIds(PdoStorage $storage): array
    {
        return array_map(fn(MigrationHistoryEntry $e) => $e->id(), iterator_to_array($storage->getApplied()));
    }

    // -------------------------------------------------------------------------
    // Basic persistence
    // -------------------------------------------------------------------------

    public function testReturnsEmptyArrayWhenTableDoesNotExist(): void
    {
        self::assertSame([], iterator_to_array($this->storage->getApplied()));
    }

    public function testMarkAppliedPersistsEntry(): void
    {
        $migration = $this->migrationFile('20240101_120000_create_users');
        $at = new DateTimeImmutable('2024-06-15T10:30:00+00:00');

        $this->storage->markApplied($migration, $at);

        $applied = iterator_to_array($this->storage->getApplied());
        self::assertCount(1, $applied);
        self::assertInstanceOf(MigrationHistoryEntry::class, $applied[0]);
        self::assertSame('20240101_120000_create_users', $applied[0]->id());
        self::assertEquals($at, $applied[0]->at());
    }

    public function testMarkAppliedDoesNotDuplicate(): void
    {
        $migration = $this->migrationFile('20240101_120000_create_users');

        $this->storage->markApplied($migration);
        $this->storage->markApplied($migration);

        self::assertCount(1, $this->storage->getApplied());
    }

    public function testMarkRevertedRemovesEntry(): void
    {
        $m1 = $this->migrationFile('20240101_120000_create_users');
        $m2 = $this->migrationFile('20240115_090000_add_index');

        $this->storage->markApplied($m1);
        $this->storage->markApplied($m2);
        $this->storage->markReverted($m1);

        self::assertSame(['20240115_090000_add_index'], $this->appliedIds($this->storage));
    }

    public function testReturnsAppliedMostRecentFirst(): void
    {
        $m1 = $this->migrationFile('20240101_120000_alpha');
        $m2 = $this->migrationFile('20240115_090000_beta');
        $m3 = $this->migrationFile('20240120_080000_gamma');

        // Apply with explicit timestamps so ordering is deterministic.
        $this->storage->markApplied($m1, new DateTimeImmutable('2024-01-01T12:00:00+00:00'));
        $this->storage->markApplied($m2, new DateTimeImmutable('2024-01-15T09:00:00+00:00'));
        $this->storage->markApplied($m3, new DateTimeImmutable('2024-01-20T08:00:00+00:00'));

        self::assertSame([
            '20240120_080000_gamma',
            '20240115_090000_beta',
            '20240101_120000_alpha',
        ], $this->appliedIds($this->storage));
    }

    // -------------------------------------------------------------------------
    // isApplied
    // -------------------------------------------------------------------------

    public function testIsAppliedReturnsTrueForAppliedMigration(): void
    {
        $m = $this->migrationFile('20240101_120000_create_users');
        $this->storage->markApplied($m);

        self::assertTrue($this->storage->isApplied($m));
    }

    public function testIsAppliedReturnsFalseForUnknownId(): void
    {
        $m = $this->migrationFile('20240101_120000_create_users');

        self::assertFalse($this->storage->isApplied($m));
    }

    public function testIsAppliedReturnsFalseWhenTableDoesNotExist(): void
    {
        $m = $this->migrationFile('20240101_120000_anything');

        self::assertFalse($this->storage->isApplied($m));
    }

    // -------------------------------------------------------------------------
    // Table auto-creation
    // -------------------------------------------------------------------------

    public function testTableIsCreatedAutomaticallyOnFirstUse(): void
    {
        // Trigger table creation via markApplied.
        $this->storage->markApplied($this->migrationFile('20240101_120000_init'));

        // Verify the table exists by querying it directly.
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM migrun_migrations');
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testCustomTableNameIsUsed(): void
    {
        $storage = new PdoStorage($this->pdo, 'schema_history');
        $storage->markApplied($this->migrationFile('20240101_120000_init'));

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM schema_history');
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testInvalidTableNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PdoStorage($this->pdo, 'bad-name!');
    }

    public function testTableNameWithSpaceThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PdoStorage($this->pdo, 'my table');
    }

    public function testTableNameStartingWithDigitThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PdoStorage($this->pdo, '1migrations');
    }

    // -------------------------------------------------------------------------
    // markReverted is a no-op for unknown IDs
    // -------------------------------------------------------------------------

    public function testMarkRevertedOnUnknownIdIsNoop(): void
    {
        $m = $this->migrationFile('20240101_120000_nonexistent');
        // Should not throw.
        $this->storage->markReverted($m);

        self::assertSame([], $this->appliedIds($this->storage));
    }
}
