<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use DateTimeImmutable;
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
        $at = new DateTimeImmutable('2024-06-15T10:30:00+00:00');

        $storage->markApplied('20240101_120000_create_users', $at);

        $applied = iterator_to_array($storage->getApplied());
        self::assertCount(1, $applied);
        self::assertInstanceOf(MigrationHistoryEntry::class, $applied[0]);
        self::assertSame('20240101_120000_create_users', $applied[0]->id());
        self::assertEquals($at, $applied[0]->at());
    }

    public function testMarkAppliedDoesNotDuplicate(): void
    {
        $storage = new JsonFileStorage($this->storagePath);

        $storage->markApplied('20240101_120000_create_users');
        $storage->markApplied('20240101_120000_create_users');

        self::assertCount(1, $storage->getApplied());
    }

    public function testMarkRevertedRemovesEntry(): void
    {
        $storage = new JsonFileStorage($this->storagePath);

        $storage->markApplied('20240101_120000_create_users');
        $storage->markApplied('20240115_090000_add_index');
        $storage->markReverted('20240101_120000_create_users');

        self::assertSame(['20240115_090000_add_index'], $this->appliedIds($storage));
    }

    public function testReturnsAppliedMostRecentFirst(): void
    {
        $storage = new JsonFileStorage($this->storagePath);

        $storage->markApplied('20240101_120000_alpha');
        $storage->markApplied('20240115_090000_beta');
        $storage->markApplied('20240120_080000_gamma');

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

    public function testThrowsOnMalformedAtTimestamp(): void
    {
        file_put_contents(
            $this->storagePath,
            json_encode([
                ['id' => '20240101_120000_create_users', 'at' => 'not-a-timestamp'],
            ], JSON_PRETTY_PRINT) . "\n",
        );
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
        $storage->markApplied('20240101_120000_create_users');

        self::assertTrue($storage->isApplied('20240101_120000_create_users'));
    }

    public function testIsAppliedReturnsFalseForUnknownId(): void
    {
        $storage = new JsonFileStorage($this->storagePath);

        self::assertFalse($storage->isApplied('20240101_120000_create_users'));
    }

    public function testIsAppliedReturnsFalseWhenFileDoesNotExist(): void
    {
        $storage = new JsonFileStorage($this->storagePath);

        self::assertFalse($storage->isApplied('20240101_120000_anything'));
    }

    // -------------------------------------------------------------------------
    // File format
    // -------------------------------------------------------------------------

    public function testReadsCurrentFormat(): void
    {
        file_put_contents(
            $this->storagePath,
            json_encode([
                ['id' => '20240101_120000_create_users', 'at' => '2024-01-01T12:00:00+00:00'],
            ], JSON_PRETTY_PRINT) . "\n",
        );

        $storage = new JsonFileStorage($this->storagePath);
        $applied = iterator_to_array($storage->getApplied());

        self::assertCount(1, $applied);
        self::assertSame('20240101_120000_create_users', $applied[0]->id());
        self::assertEquals(
            new DateTimeImmutable('2024-01-01T12:00:00+00:00'),
            $applied[0]->at(),
        );
    }
}
