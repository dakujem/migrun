<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use DateTimeImmutable;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\Storage\SqliteStorage;
use PHPUnit\Framework\TestCase;

/**
 * Tests SqliteStorage end-to-end using a real SQLite file in the system temp
 * directory. The file is cleaned up after every test.
 *
 * The detailed behavioural contract is already covered by PdoStorageTest; this
 * suite focuses on the file-management concerns specific to SqliteStorage
 * (directory auto-creation, file creation, correct delegation).
 */
final class SqliteStorageTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/migrun_test_' . uniqid() . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Extract IDs from getApplied() for assertions that only care about ordering. */
    private function appliedIds(SqliteStorage $storage): array
    {
        return array_map(fn(MigrationHistoryEntry $e) => $e->id(), iterator_to_array($storage->getApplied()));
    }

    // -------------------------------------------------------------------------
    // File management
    // -------------------------------------------------------------------------

    public function testCreatesDbFileOnFirstWrite(): void
    {
        $storage = new SqliteStorage($this->dbPath);
        // SQLite creates the file as soon as the PDO connection is opened.
        $storage->markApplied('20240101_120000_init');

        self::assertFileExists($this->dbPath);
    }

    public function testCreatesParentDirectoryIfMissing(): void
    {
        $dir = sys_get_temp_dir() . '/migrun_test_dir_' . uniqid();
        $path = $dir . '/history.sqlite';

        try {
            $storage = new SqliteStorage($path);
            $storage->markApplied('20240101_120000_init');

            self::assertDirectoryExists($dir);
            self::assertFileExists($path);

            // Release the PDO connection so Windows can delete the file.
            unset($storage);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Delegation — basic behavioural smoke tests
    // -------------------------------------------------------------------------

    public function testReturnsEmptyWhenNoMigrationsApplied(): void
    {
        $storage = new SqliteStorage($this->dbPath);
        self::assertSame([], iterator_to_array($storage->getApplied()));
    }

    public function testMarkAppliedAndIsApplied(): void
    {
        $storage = new SqliteStorage($this->dbPath);
        $at = new DateTimeImmutable('2024-06-15T10:30:00+00:00');

        $storage->markApplied('20240101_120000_create_users', $at);

        self::assertTrue($storage->isApplied('20240101_120000_create_users'));

        $applied = iterator_to_array($storage->getApplied());
        self::assertCount(1, $applied);
        self::assertSame('20240101_120000_create_users', $applied[0]->id());
        self::assertEquals($at, $applied[0]->at());
    }

    public function testMarkReverted(): void
    {
        $storage = new SqliteStorage($this->dbPath);

        $storage->markApplied('20240101_120000_create_users');
        $storage->markApplied('20240115_090000_add_index');
        $storage->markReverted('20240101_120000_create_users');

        self::assertSame(['20240115_090000_add_index'], $this->appliedIds($storage));
    }

    public function testOrderingIsRecentFirst(): void
    {
        $storage = new SqliteStorage($this->dbPath);

        $storage->markApplied('20240101_alpha', new DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $storage->markApplied('20240201_beta', new DateTimeImmutable('2024-02-01T00:00:00+00:00'));

        self::assertSame(['20240201_beta', '20240101_alpha'], $this->appliedIds($storage));
    }

    public function testCustomTableName(): void
    {
        $storage = new SqliteStorage($this->dbPath, 'schema_history');

        $storage->markApplied('20240101_120000_init');

        self::assertTrue($storage->isApplied('20240101_120000_init'));
    }

    public function testDataPersistsAcrossInstances(): void
    {
        $storage1 = new SqliteStorage($this->dbPath);
        $storage1->markApplied('20240101_120000_create_users');

        // Open a second instance pointing to the same file.
        $storage2 = new SqliteStorage($this->dbPath);
        self::assertTrue($storage2->isApplied('20240101_120000_create_users'));
    }
}
