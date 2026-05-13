<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use DateTimeImmutable;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\MigrationRun;
use Dakujem\Migrun\MigrationState;
use Dakujem\Migrun\MigrationStatusEntry;
use PHPUnit\Framework\TestCase;

final class ValueObjectTest extends TestCase
{
    // -----------------------------------------------------------------------
    // MigrationRun
    // -----------------------------------------------------------------------

    public function testMigrationRunStoresFileAndDuration(): void
    {
        $file = new MigrationFile('/m/20240101_test.php', '20240101_test');
        $run = new MigrationRun(file: $file, durationSeconds: 0.123);

        self::assertSame($file, $run->file);
        self::assertEqualsWithDelta(0.123, $run->durationSeconds, 1e-9);
    }

    public function testMigrationRunIdDelegatesToFile(): void
    {
        $file = new MigrationFile('/m/20240101_create_users.php', '20240101_create_users');
        $run = new MigrationRun(file: $file, durationSeconds: 0.0);

        self::assertSame('20240101_create_users', $run->id());
    }

    public function testMigrationRunDurationIsZero(): void
    {
        $file = new MigrationFile('/m/20240101_fast.php', '20240101_fast');
        $run = new MigrationRun(file: $file, durationSeconds: 0.0);

        self::assertSame(0.0, $run->durationSeconds);
    }

    // -----------------------------------------------------------------------
    // MigrationHistoryEntry
    // -----------------------------------------------------------------------

    public function testMigrationHistoryEntryStoresIdAndTimestamp(): void
    {
        $at = new DateTimeImmutable('2024-01-15 10:00:00');
        $entry = new MigrationHistoryEntry(id: '20240115_init', at: $at);

        self::assertSame('20240115_init', $entry->id());
        self::assertSame($at, $entry->at());
    }

    public function testMigrationHistoryEntryAtReturnsExactInstance(): void
    {
        $at = new DateTimeImmutable();
        $entry = new MigrationHistoryEntry(id: 'any', at: $at);

        self::assertSame($at, $entry->at());
    }

    // -----------------------------------------------------------------------
    // MigrationStatusEntry
    // -----------------------------------------------------------------------

    public function testMigrationStatusEntryApplied(): void
    {
        $at = new DateTimeImmutable('2024-06-01 08:00:00');
        $entry = new MigrationStatusEntry(
            id: '20240601_create_table',
            state: MigrationState::Applied,
            appliedAt: $at,
            path: '/m/20240601_create_table.php',
        );

        self::assertSame('20240601_create_table', $entry->id);
        self::assertSame(MigrationState::Applied, $entry->state);
        self::assertSame($at, $entry->appliedAt);
        self::assertSame('/m/20240601_create_table.php', $entry->path);
    }

    public function testMigrationStatusEntryPending(): void
    {
        $entry = new MigrationStatusEntry(
            id: '20240701_add_column',
            state: MigrationState::Pending,
            appliedAt: null,
            path: '/m/20240701_add_column.php',
        );

        self::assertSame(MigrationState::Pending, $entry->state);
        self::assertNull($entry->appliedAt);
        self::assertNotNull($entry->path);
    }

    public function testMigrationStatusEntryMissing(): void
    {
        $at = new DateTimeImmutable();
        $entry = new MigrationStatusEntry(
            id: '20230101_deleted',
            state: MigrationState::Missing,
            appliedAt: $at,
            path: null,
        );

        self::assertSame(MigrationState::Missing, $entry->state);
        self::assertNotNull($entry->appliedAt);
        self::assertNull($entry->path);
    }
}
