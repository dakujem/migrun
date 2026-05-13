<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use DateTimeImmutable;
use DateTimeInterface;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\Storage\MysqliStorage;
use LengthException;
use mysqli;
use mysqli_result;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Full behavioural coverage of MysqliStorage using mock mysqli objects.
 * No live MySQL connection required.
 *
 * Because mysqli_result::num_rows is a real property (not a method), we use
 * a hand-rolled stub rather than createMock(), which cannot stub properties.
 */
final class MysqliStorageMockTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Returns a mysqli mock whose CREATE TABLE query always succeeds (true). */
    private function mysqliWithTable(): mysqli
    {
        $m = $this->createMock(mysqli::class);
        $m->method('query')->willReturnCallback(function (string $sql) {
            if (str_starts_with(trim($sql), 'CREATE')) {
                return true;
            }
            return false; // callers override with their own willReturnCallback
        });
        return $m;
    }

    /**
     * Returns a mysqli mock where:
     *   - CREATE TABLE → true
     *   - all other query() calls → $queryCallback($sql)
     *   - real_escape_string() → returns the input unchanged
     */
    private function mysqli(callable $queryCallback): mysqli
    {
        $m = $this->createMock(mysqli::class);
        $m->method('real_escape_string')->willReturnArgument(0);
        $m->method('query')->willReturnCallback(function (string $sql) use ($queryCallback) {
            if (str_starts_with(trim($sql), 'CREATE')) {
                return true;
            }
            return $queryCallback($sql);
        });
        return $m;
    }

    /** Builds a fake mysqli_result-like object with a fixed sequence of rows. */
    private function resultWith(array $rows): FakeMysqliResult
    {
        return new FakeMysqliResult($rows);
    }

    private function storage(mysqli $m, string $table = 'migrations'): MysqliStorage
    {
        return new MysqliStorage($m, $table);
    }

    // -----------------------------------------------------------------------
    // ensureTable — runs once, subsequent calls skip the query
    // -----------------------------------------------------------------------

    public function testEnsureTableRunsOnlyOnce(): void
    {
        $createCount = 0;
        $m = $this->createMock(mysqli::class);
        $m->method('real_escape_string')->willReturnArgument(0);
        $m->method('query')->willReturnCallback(function (string $sql) use (&$createCount) {
            if (str_starts_with(trim($sql), 'CREATE')) {
                $createCount++;
                return true;
            }
            return $this->resultWith([], 0); // SELECT for isApplied
        });

        $storage = $this->storage($m);
        $storage->isApplied('20240101_a'); // triggers ensureTable
        $storage->isApplied('20240101_b'); // must NOT trigger ensureTable again

        self::assertSame(1, $createCount);
    }

    // -----------------------------------------------------------------------
    // ensureTable — throws when CREATE TABLE fails
    // -----------------------------------------------------------------------

    public function testEnsureTableThrowsWhenQueryFails(): void
    {
        $m = $this->createMock(mysqli::class);
        $m->method('query')->willReturn(false);

        $storage = $this->storage($m);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not create migration storage table/');
        $storage->getApplied();
    }

    // -----------------------------------------------------------------------
    // getApplied — empty table
    // -----------------------------------------------------------------------

    public function testGetAppliedReturnsEmptyWhenNoRows(): void
    {
        $m = $this->mysqli(fn() => $this->resultWith([]));
        $storage = $this->storage($m);

        self::assertSame([], iterator_to_array($storage->getApplied()));
    }

    // -----------------------------------------------------------------------
    // getApplied — returns entries with correct id and timestamp
    // -----------------------------------------------------------------------

    public function testGetAppliedReturnsEntries(): void
    {
        $at = '2024-06-15T10:30:00+00:00';
        $rows = [
            ['id' => '20240615_create_users', 'applied_at' => $at],
            ['id' => '20240101_init',         'applied_at' => '2024-01-01T00:00:00+00:00'],
        ];

        $m = $this->mysqli(fn() => $this->resultWith($rows));
        $storage = $this->storage($m);

        $entries = iterator_to_array($storage->getApplied());
        self::assertCount(2, $entries);
        self::assertInstanceOf(MigrationHistoryEntry::class, $entries[0]);
        self::assertSame('20240615_create_users', $entries[0]->id());
        self::assertEquals(new DateTimeImmutable($at), $entries[0]->at());
    }

    // -----------------------------------------------------------------------
    // getApplied — query() returns false
    // -----------------------------------------------------------------------

    public function testGetAppliedThrowsWhenQueryReturnsFalse(): void
    {
        $m = $this->mysqli(fn() => false);
        $storage = $this->storage($m);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not query/');
        $storage->getApplied();
    }

    // -----------------------------------------------------------------------
    // getApplied — corrupted timestamp in row
    // -----------------------------------------------------------------------

    public function testGetAppliedThrowsOnCorruptedTimestamp(): void
    {
        $rows = [['id' => '20240101_init', 'applied_at' => 'not-a-timestamp']];
        $m = $this->mysqli(fn() => $this->resultWith($rows));
        $storage = $this->storage($m);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/corrupted timestamp/');
        $storage->getApplied();
    }

    // -----------------------------------------------------------------------
    // getApplied — ID in row exceeds MaximumIdLength
    // -----------------------------------------------------------------------

    public function testGetAppliedThrowsWhenStoredIdTooLong(): void
    {
        $rows = [[
            'id'         => str_repeat('x', MysqliStorage::MaximumIdLength + 1),
            'applied_at' => '2024-01-01T00:00:00+00:00',
        ]];
        $m = $this->mysqli(fn() => $this->resultWith($rows));
        $storage = $this->storage($m);

        $this->expectException(LengthException::class);
        $storage->getApplied();
    }

    // -----------------------------------------------------------------------
    // getApplied — unexpected row structure (not a keyed array)
    // -----------------------------------------------------------------------

    public function testGetAppliedThrowsOnUnexpectedRowStructure(): void
    {
        // fetch_assoc returns a row missing both expected keys.
        $rows = [['wrong_key' => 'value']];
        $m = $this->mysqli(fn() => $this->resultWith($rows));
        $storage = $this->storage($m);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unexpected row structure/');
        $storage->getApplied();
    }

    // -----------------------------------------------------------------------
    // isApplied — found
    // -----------------------------------------------------------------------

    public function testIsAppliedReturnsTrueWhenRowFound(): void
    {
        $m = $this->mysqli(fn() => $this->resultWith([['1' => 1]])); // non-empty → found
        $storage = $this->storage($m);

        self::assertTrue($storage->isApplied('20240101_init'));
    }

    // -----------------------------------------------------------------------
    // isApplied — not found
    // -----------------------------------------------------------------------

    public function testIsAppliedReturnsFalseWhenRowNotFound(): void
    {
        $m = $this->mysqli(fn() => $this->resultWith([])); // empty → not found
        $storage = $this->storage($m);

        self::assertFalse($storage->isApplied('20240101_init'));
    }

    // -----------------------------------------------------------------------
    // isApplied — query() returns false
    // -----------------------------------------------------------------------

    public function testIsAppliedThrowsWhenQueryReturnsFalse(): void
    {
        $m = $this->mysqli(fn() => false);
        $storage = $this->storage($m);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not query/');
        $storage->isApplied('20240101_init');
    }

    // -----------------------------------------------------------------------
    // markApplied — inserts when not already applied
    // -----------------------------------------------------------------------

    public function testMarkAppliedInsertsNewEntry(): void
    {
        $inserted = false;
        $m = $this->mysqli(function (string $sql) use (&$inserted) {
            if (str_starts_with(trim($sql), 'SELECT')) {
                return $this->resultWith([]); // not yet applied
            }
            if (str_starts_with(trim($sql), 'INSERT')) {
                $inserted = true;
                return true;
            }
            return false;
        });
        $storage = $this->storage($m);

        $storage->markApplied('20240101_init', new DateTimeImmutable('2024-01-01T00:00:00+00:00'));

        self::assertTrue($inserted);
    }

    // -----------------------------------------------------------------------
    // markApplied — skips when already applied (duplicate guard)
    // -----------------------------------------------------------------------

    public function testMarkAppliedSkipsWhenAlreadyApplied(): void
    {
        $insertCount = 0;
        $m = $this->mysqli(function (string $sql) use (&$insertCount) {
            if (str_starts_with(trim($sql), 'SELECT')) {
                return $this->resultWith([['1' => 1]]); // already applied
            }
            if (str_starts_with(trim($sql), 'INSERT')) {
                $insertCount++;
                return true;
            }
            return false;
        });
        $storage = $this->storage($m);

        $storage->markApplied('20240101_init');

        self::assertSame(0, $insertCount);
    }

    // -----------------------------------------------------------------------
    // markApplied — INSERT query() returns false
    // -----------------------------------------------------------------------

    public function testMarkAppliedThrowsWhenInsertFails(): void
    {
        $m = $this->mysqli(function (string $sql) {
            if (str_starts_with(trim($sql), 'SELECT')) {
                return $this->resultWith([]); // not yet applied
            }
            return false; // INSERT fails
        });
        $storage = $this->storage($m);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not insert/');
        $storage->markApplied('20240101_init');
    }

    // -----------------------------------------------------------------------
    // markReverted — deletes the row
    // -----------------------------------------------------------------------

    public function testMarkRevertedDeletesEntry(): void
    {
        $deleted = false;
        $m = $this->mysqli(function (string $sql) use (&$deleted) {
            if (str_starts_with(trim($sql), 'DELETE')) {
                $deleted = true;
                return true;
            }
            return false;
        });
        $storage = $this->storage($m);

        $storage->markReverted('20240101_init');

        self::assertTrue($deleted);
    }

    // -----------------------------------------------------------------------
    // markReverted — query() returns false
    // -----------------------------------------------------------------------

    public function testMarkRevertedThrowsWhenQueryFails(): void
    {
        $m = $this->mysqli(fn() => false);
        $storage = $this->storage($m);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not delete/');
        $storage->markReverted('20240101_init');
    }

    // -----------------------------------------------------------------------
    // ID length guard — all three write methods
    // -----------------------------------------------------------------------

    public function testMarkAppliedThrowsWhenIdTooLong(): void
    {
        $m = $this->createMock(mysqli::class);
        $storage = $this->storage($m);

        $this->expectException(LengthException::class);
        $storage->markApplied(str_repeat('a', MysqliStorage::MaximumIdLength + 1));
    }

    public function testIsAppliedThrowsWhenIdTooLong(): void
    {
        $m = $this->createMock(mysqli::class);
        $storage = $this->storage($m);

        $this->expectException(LengthException::class);
        $storage->isApplied(str_repeat('a', MysqliStorage::MaximumIdLength + 1));
    }

    public function testMarkRevertedThrowsWhenIdTooLong(): void
    {
        $m = $this->createMock(mysqli::class);
        $storage = $this->storage($m);

        $this->expectException(LengthException::class);
        $storage->markReverted(str_repeat('a', MysqliStorage::MaximumIdLength + 1));
    }
}

/**
 * Hand-rolled stub for mysqli_result.
 *
 * Must extend mysqli_result so PHP's return type enforcement on mysqli::query()
 * (which returns mysqli_result|bool) is satisfied.
 *
 * We cannot set num_rows (it is readonly in PHP 8.1+), so MysqliStorage::isApplied
 * was refactored to use fetch_assoc() instead of num_rows.
 */
final class FakeMysqliResult extends mysqli_result
{
    private int $index = 0;

    /** @param array<array<string,mixed>> $rows */
    public function __construct(private array $rows)
    {
        // Do NOT call parent::__construct — requires a live mysqli connection.
    }

    public function fetch_assoc(): array|null|false
    {
        if ($this->index >= count($this->rows)) {
            return null;
        }
        return $this->rows[$this->index++];
    }

    public function free(): void
    {
        // no-op
    }
}
