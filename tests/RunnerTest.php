<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Direction;
use Dakujem\Migrun\Exception\MigrationNotFoundException;
use Dakujem\Migrun\ExecutesMigrations;
use Dakujem\Migrun\DiscoversMigrations;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\Orchestrator;
use Dakujem\Migrun\TracksMigrations;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Stub classes — named so they can be used as inspectable return types
// ---------------------------------------------------------------------------

final class SpyStorage implements TracksMigrations
{
    public array $applied;
    public array $markedApplied = [];
    public array $markedReverted = [];

    /**
     * @param string[] $applied     The windowed list of IDs returned by getApplied().
     * @param string[] $fullHistory Full history for isApplied(); if empty defaults to $applied.
     */
    public function __construct(
        array $applied = [],
        private readonly array $fullHistory = [],
    ) {
        $this->applied = $applied;
    }

    /**
     * Returns MigrationHistoryEntry[] reconstructed from the stored IDs, most-recent-first.
     *
     * @return MigrationHistoryEntry[]
     */
    public function getApplied(): iterable
    {
        return array_map(
            fn(string $id) => new MigrationHistoryEntry(id: $id, ranAt: new \DateTimeImmutable()),
            array_reverse($this->applied),
        );
    }

    public function isApplied(MigrationFile $migration): bool
    {
        $history = $this->fullHistory !== [] ? $this->fullHistory : $this->applied;
        return in_array($migration->id(), $history, strict: true);
    }

    public function markApplied(MigrationFile $migration, ?\DateTimeImmutable $at = null): void
    {
        $this->applied[] = $migration->id();
        $this->markedApplied[] = $migration->id();
    }

    public function markReverted(MigrationFile $migration, ?\DateTimeImmutable $at = null): void
    {
        $this->applied = array_values(array_filter($this->applied, fn($id) => $id !== $migration->id()));
        $this->markedReverted[] = $migration->id();
    }
}

final class SpyExecutor implements ExecutesMigrations
{
    public array $executed = [];

    public function execute(MigrationFile $migration, Direction $direction): void
    {
        $this->executed[] = [$migration->id(), $direction];
    }
}

// ---------------------------------------------------------------------------

final class RunnerTest extends TestCase
{
    private function migration(string $id): MigrationFile
    {
        return new MigrationFile(path: "/migrations/{$id}.php", id: $id);
    }

    private function stubFinder(array $migrations): DiscoversMigrations
    {
        return new class($migrations) implements DiscoversMigrations {
            public function __construct(private array $migrations) {}

            public function list(): iterable
            {
                return $this->migrations;
            }

            public function find(array $migrations): array
            {
                $indexed = [];
                foreach ($this->migrations as $m) {
                    $indexed[$m->id()] = $m;
                }
                $result = [];
                foreach ($migrations as $entry) {
                    $id = $entry instanceof MigrationHistoryEntry ? $entry->id() : $entry;
                    if (!isset($indexed[$id])) {
                        throw new MigrationNotFoundException($id);
                    }
                    $result[$id] = $indexed[$id];
                }
                return $result;
            }
        };
    }

    public function testRunExecutesPendingMigrationsInOrder(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $m2 = $this->migration('20240115_090000_beta');
        $m3 = $this->migration('20240120_080000_gamma');

        $storage = new SpyStorage([$m1->id()]); // m1 already applied
        $finder = $this->stubFinder([$m1, $m2, $m3]);
        $executor = new SpyExecutor();

        $runner = new Orchestrator($storage, $finder, $executor);
        $executed = $runner->run();

        self::assertCount(2, $executed);
        self::assertSame($m2->id(), $executed[0]->id());
        self::assertSame($m3->id(), $executed[1]->id());

        self::assertSame(
            [[$m2->id(), Direction::Up], [$m3->id(), Direction::Up]],
            $executor->executed,
        );

        self::assertSame([$m2->id(), $m3->id()], $storage->markedApplied);
    }

    public function testRunDoesNothingWhenAllMigrationsAlreadyRan(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');

        $storage = new SpyStorage([$m1->id()]);
        $finder = $this->stubFinder([$m1]);
        $executor = new SpyExecutor();

        $runner = new Orchestrator($storage, $finder, $executor);
        $executed = $runner->run();

        self::assertSame([], $executed);
        self::assertSame([], $executor->executed);
    }

    public function testRollbackRevertsLastMigration(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $m2 = $this->migration('20240115_090000_beta');

        $storage = new SpyStorage([$m1->id(), $m2->id()]);
        $finder = $this->stubFinder([$m1, $m2]);
        $executor = new SpyExecutor();

        $runner = new Orchestrator($storage, $finder, $executor);
        $reverted = $runner->rollback(1);

        self::assertCount(1, $reverted);
        self::assertSame($m2->id(), $reverted[0]->id());

        self::assertSame([[$m2->id(), Direction::Down]], $executor->executed);
        self::assertSame([$m2->id()], $storage->markedReverted);
    }

    public function testRollbackMultipleSteps(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $m2 = $this->migration('20240115_090000_beta');
        $m3 = $this->migration('20240120_080000_gamma');

        $storage = new SpyStorage([$m1->id(), $m2->id(), $m3->id()]);
        $finder = $this->stubFinder([$m1, $m2, $m3]);
        $executor = new SpyExecutor();

        $runner = new Orchestrator($storage, $finder, $executor);
        $reverted = $runner->rollback(2);

        self::assertCount(2, $reverted);
        // Last-in-first-out: gamma first, then beta
        self::assertSame($m3->id(), $reverted[0]->id());
        self::assertSame($m2->id(), $reverted[1]->id());
    }

    public function testRollbackThrowsWhenMigrationNotFoundOnDisk(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');

        // Storage says m1 ran, but finder returns nothing
        $storage = new SpyStorage([$m1->id()]);
        $finder = $this->stubFinder([]);
        $executor = new SpyExecutor();

        $runner = new Orchestrator($storage, $finder, $executor);

        $this->expectException(MigrationNotFoundException::class);
        $runner->rollback(1);
    }

    public function testMarkAppliedIsCalledAfterEachSuccessfulExecution(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $m2 = $this->migration('20240115_090000_beta');

        $storage = new SpyStorage();
        $finder = $this->stubFinder([$m1, $m2]);

        // Executor that fails on m2
        $executor = new class implements ExecutesMigrations {
            public array $executed = [];

            public function execute(MigrationFile $migration, Direction $direction): void
            {
                if ($migration->id() === '20240115_090000_beta') {
                    throw new \RuntimeException('Migration failed.');
                }
                $this->executed[] = $migration->id();
            }
        };

        $runner = new Orchestrator($storage, $finder, $executor);

        try {
            $runner->run();
        } catch (\RuntimeException) {
            // expected
        }

        // m1 should be marked as applied, m2 should not
        self::assertSame([$m1->id()], $storage->markedApplied);
    }

    public function testPreCutoffMigrationsAreNotReRunWhenStorageHasFullHistory(): void
    {
        // Simulates a storage with a windowed getApplied(): only recent IDs in the
        // window, but isApplied() knows the full history. The finder still surfaces
        // the old migration. The orchestrator must NOT re-run it.
        $old    = $this->migration('20200101_120000_legacy');
        $recent = $this->migration('20240101_120000_recent');

        // getApplied() returns only the recent window; fullHistory contains everything
        $storage = new SpyStorage(
            applied: [$recent->id()],
            fullHistory: [$old->id(), $recent->id()],
        );
        $finder = $this->stubFinder([$old, $recent]); // finder returns both
        $executor = new SpyExecutor();

        $runner = new Orchestrator($storage, $finder, $executor);
        $executed = $runner->run();

        // Neither migration should be re-run
        self::assertSame([], $executed);
        self::assertSame([], $executor->executed);
    }
}
