<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use DateTimeImmutable;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\Storage\MysqliStorage;
use InvalidArgumentException;
use mysqli;
use PHPUnit\Framework\TestCase;

/**
 * Tests MysqliStorage against a real MySQL/MariaDB server.
 *
 * These tests are skipped automatically when:
 *   - the mysqli extension is not loaded, or
 *   - no MySQL server is reachable at the coordinates below.
 *
 * Override the connection details via environment variables:
 *   MYSQL_HOST, MYSQL_PORT, MYSQL_USER, MYSQL_PASS, MYSQL_DB
 */
final class MysqliStorageTest extends TestCase
{
    private static string $host = 'localhost';
    private static int    $port = 3306;
    private static string $user = 'root';
    private static string $pass = '';
    private static string $db   = 'migrun_test';

    private mysqli $conn;
    private string $table;

    protected function setUp(): void
    {
        $this->conn  = $this->connectOrSkip();
        $this->table = 'migrun_test_' . substr(md5(uniqid()), 0, 8);
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            $this->conn->query("DROP TABLE IF EXISTS `{$this->table}`");
            $this->conn->close();
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function connectOrSkip(): mysqli
    {
        if (!extension_loaded('mysqli')) {
            self::markTestSkipped('mysqli extension is not available.');
        }

        $host = (string) (getenv('MYSQL_HOST') ?: self::$host);
        $port = (int)    (getenv('MYSQL_PORT') ?: self::$port);
        $user = (string) (getenv('MYSQL_USER') ?: self::$user);
        $pass = (string) (getenv('MYSQL_PASS') ?: self::$pass);
        $db   = (string) (getenv('MYSQL_DB')   ?: self::$db);

        // Suppress the connection error — we'll check connect_errno instead.
        try {
            $conn = @new mysqli($host, $user, $pass, $db, $port);
        } catch (\mysqli_sql_exception $e) {
            self::markTestSkipped("MySQL not reachable ({$e->getMessage()}). Set MYSQL_HOST/USER/PASS/DB to run these tests.");
        }
        if ($conn->connect_errno) {
            self::markTestSkipped("MySQL not reachable ({$conn->connect_error}). Set MYSQL_HOST/USER/PASS/DB to run these tests.");
        }
        $conn->set_charset('utf8mb4');
        return $conn;
    }

    private function migrationFile(string $id): MigrationFile
    {
        return new MigrationFile(path: "/migrations/{$id}.php", id: $id);
    }

    private function storage(): MysqliStorage
    {
        return new MysqliStorage($this->conn, $this->table);
    }

    /** Extract IDs from getApplied() for assertions that only care about ordering. */
    private function appliedIds(MysqliStorage $storage): array
    {
        return array_map(fn(MigrationHistoryEntry $e) => $e->id(), iterator_to_array($storage->getApplied()));
    }

    // -------------------------------------------------------------------------
    // Basic persistence
    // -------------------------------------------------------------------------

    public function testReturnsEmptyWhenTableDoesNotExist(): void
    {
        self::assertSame([], iterator_to_array($this->storage()->getApplied()));
    }

    public function testMarkAppliedPersistsEntry(): void
    {
        $storage   = $this->storage();
        $migration = $this->migrationFile('20240101_120000_create_users');
        $at        = new DateTimeImmutable('2024-06-15T10:30:00+00:00');

        $storage->markApplied($migration, $at);

        $applied = iterator_to_array($storage->getApplied());
        self::assertCount(1, $applied);
        self::assertInstanceOf(MigrationHistoryEntry::class, $applied[0]);
        self::assertSame('20240101_120000_create_users', $applied[0]->id());
        self::assertEquals($at, $applied[0]->at());
    }

    public function testMarkAppliedDoesNotDuplicate(): void
    {
        $storage   = $this->storage();
        $migration = $this->migrationFile('20240101_120000_create_users');

        $storage->markApplied($migration);
        $storage->markApplied($migration);

        self::assertCount(1, $storage->getApplied());
    }

    public function testMarkRevertedRemovesEntry(): void
    {
        $storage = $this->storage();
        $m1      = $this->migrationFile('20240101_120000_create_users');
        $m2      = $this->migrationFile('20240115_090000_add_index');

        $storage->markApplied($m1);
        $storage->markApplied($m2);
        $storage->markReverted($m1);

        self::assertSame(['20240115_090000_add_index'], $this->appliedIds($storage));
    }

    public function testReturnsAppliedMostRecentFirst(): void
    {
        $storage = $this->storage();
        $m1      = $this->migrationFile('20240101_120000_alpha');
        $m2      = $this->migrationFile('20240115_090000_beta');
        $m3      = $this->migrationFile('20240120_080000_gamma');

        $storage->markApplied($m1, new DateTimeImmutable('2024-01-01T12:00:00+00:00'));
        $storage->markApplied($m2, new DateTimeImmutable('2024-01-15T09:00:00+00:00'));
        $storage->markApplied($m3, new DateTimeImmutable('2024-01-20T08:00:00+00:00'));

        self::assertSame([
            '20240120_080000_gamma',
            '20240115_090000_beta',
            '20240101_120000_alpha',
        ], $this->appliedIds($storage));
    }

    // -------------------------------------------------------------------------
    // isApplied
    // -------------------------------------------------------------------------

    public function testIsAppliedReturnsTrueForAppliedMigration(): void
    {
        $storage = $this->storage();
        $m       = $this->migrationFile('20240101_120000_create_users');
        $storage->markApplied($m);

        self::assertTrue($storage->isApplied($m));
    }

    public function testIsAppliedReturnsFalseForUnknownId(): void
    {
        $storage = $this->storage();
        $m       = $this->migrationFile('20240101_120000_create_users');

        self::assertFalse($storage->isApplied($m));
    }

    public function testIsAppliedReturnsFalseWhenTableDoesNotExist(): void
    {
        self::assertFalse($this->storage()->isApplied($this->migrationFile('anything')));
    }

    // -------------------------------------------------------------------------
    // Table auto-creation
    // -------------------------------------------------------------------------

    public function testTableIsCreatedAutomaticallyOnFirstUse(): void
    {
        $storage = $this->storage();
        $storage->markApplied($this->migrationFile('20240101_120000_init'));

        $result = $this->conn->query("SELECT COUNT(*) AS n FROM `{$this->table}`");
        self::assertSame(1, (int) $result->fetch_assoc()['n']);
        $result->free();
    }

    // -------------------------------------------------------------------------
    // markReverted is a no-op for unknown IDs
    // -------------------------------------------------------------------------

    public function testMarkRevertedOnUnknownIdIsNoop(): void
    {
        $storage = $this->storage();
        $storage->markReverted($this->migrationFile('nonexistent'));

        self::assertSame([], $this->appliedIds($storage));
    }

    // -------------------------------------------------------------------------
    // Invalid table name
    // -------------------------------------------------------------------------

    public function testInvalidTableNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MysqliStorage($this->conn, 'bad-name!');
    }
}
