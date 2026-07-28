<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Direction;
use Dakujem\Migrun\Exception\MigrationNotFoundException;
use Dakujem\Migrun\ExecutesMigrations;
use Dakujem\Migrun\DiscoversMigrations;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\MigrationState;
use Dakujem\Migrun\Orchestrator;
use Dakujem\Migrun\RunsMigrations;
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
     * @param string[]                         $applied     The windowed list of IDs returned by getApplied().
     * @param string[]                         $fullHistory Full history for isApplied(); if empty defaults to $applied.
     * @param array<string,\DateTimeInterface> $timestamps  Optional per-ID timestamps for getApplied().
     */
    public function __construct(
        array $applied = [],
        private readonly array $fullHistory = [],
        private readonly array $timestamps = [],
    ) {
        $this->applied = $applied;
    }

    /**
     * Returns MigrationHistoryEntry[] reconstructed from the stored IDs.
     *
     * Timestamps default to a fixed base plus one second per position, so that the
     * n-th applied migration is stamped later than the (n-1)-th — as it would be in
     * reality. Deterministic, unlike "now" for every entry.
     *
     * Deliberately returned most-recent-first (i.e. NOT in insertion order): the
     * storage contract says order is not significant, and the orchestrator must
     * impose its own regardless of what it receives.
     *
     * @return MigrationHistoryEntry[]
     */
    public function getApplied(): iterable
    {
        $base = new \DateTimeImmutable('2024-01-01 00:00:00');

        $entries = [];
        foreach (array_values($this->applied) as $i => $id) {
            $entries[] = new MigrationHistoryEntry(
                id: $id,
                at: $this->timestamps[$id] ?? $base->modify("+{$i} seconds"),
            );
        }

        return array_reverse($entries);
    }

    public function isApplied(string $id): bool
    {
        $history = $this->fullHistory !== [] ? $this->fullHistory : $this->applied;
        return in_array($id, $history, strict: true);
    }

    public function markApplied(string $id, ?\DateTimeInterface $at = null): void
    {
        $this->applied[] = $id;
        $this->markedApplied[] = $id;
    }

    public function markReverted(string $id): void
    {
        $this->applied = array_values(array_filter($this->applied, fn($i) => $i !== $id));
        $this->markedReverted[] = $id;
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

/**
 * Records every reporter call as a [event, id, direction] tuple, in order.
 */
final class SpyReporter implements \Dakujem\Migrun\ReportsMigrations
{
    /** @var array<int, array{0:string, 1:string, 2:Direction}> */
    public array $events = [];
    /** @var float[] Durations captured on each finished() call. */
    public array $durations = [];

    public function starting(MigrationFile $file, Direction $direction): void
    {
        $this->events[] = ['starting', $file->id(), $direction];
    }

    public function finished(\Dakujem\Migrun\MigrationRun $run, Direction $direction): void
    {
        $this->events[] = ['finished', $run->id(), $direction];
        $this->durations[] = $run->durationSeconds;
    }

    public function failed(MigrationFile $file, Direction $direction, \Throwable $error): void
    {
        $this->events[] = ['failed', $file->id(), $direction];
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

    // -------------------------------------------------------------------------
    // status()
    // -------------------------------------------------------------------------

    public function testStatusAllPending(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $m2 = $this->migration('20240115_090000_beta');
        $m3 = $this->migration('20240120_080000_gamma');

        // No history at all
        $storage  = new SpyStorage([]);
        $finder   = $this->stubFinder([$m1, $m2, $m3]);
        $executor = new SpyExecutor();

        $runner  = new Orchestrator($storage, $finder, $executor);
        $entries = iterator_to_array($runner->status());

        self::assertCount(3, $entries);

        // Ordered ascending by ID
        self::assertSame($m1->id(), $entries[0]->id);
        self::assertSame($m2->id(), $entries[1]->id);
        self::assertSame($m3->id(), $entries[2]->id);

        foreach ($entries as $entry) {
            self::assertSame(MigrationState::Pending, $entry->state);
            self::assertNull($entry->appliedAt);
            self::assertNotNull($entry->path);
        }
    }

    public function testStatusAllApplied(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $m2 = $this->migration('20240115_090000_beta');

        $at1 = new \DateTimeImmutable('2024-01-01 12:05:00');
        $at2 = new \DateTimeImmutable('2024-01-15 09:10:00');

        $storage = new SpyStorage(
            applied: [$m1->id(), $m2->id()],
            timestamps: [$m1->id() => $at1, $m2->id() => $at2],
        );

        $finder   = $this->stubFinder([$m1, $m2]);
        $executor = new SpyExecutor();

        $runner  = new Orchestrator($storage, $finder, $executor);
        $entries = iterator_to_array($runner->status());

        self::assertCount(2, $entries);

        self::assertSame($m1->id(), $entries[0]->id);
        self::assertSame(MigrationState::Applied, $entries[0]->state);
        self::assertSame($at1->getTimestamp(), $entries[0]->appliedAt->getTimestamp());
        self::assertSame("/migrations/{$m1->id()}.php", $entries[0]->path);

        self::assertSame($m2->id(), $entries[1]->id);
        self::assertSame(MigrationState::Applied, $entries[1]->state);
        self::assertSame($at2->getTimestamp(), $entries[1]->appliedAt->getTimestamp());
        self::assertSame("/migrations/{$m2->id()}.php", $entries[1]->path);
    }

    public function testStatusMixed(): void
    {
        // Scenario:
        //   alpha  — applied (in history + on disk)
        //   beta   — pending (on disk only)
        //   gamma  — missing (in history only, file gone)
        //   delta  — applied (in history + on disk)
        $alpha = $this->migration('20240101_alpha');
        $beta  = $this->migration('20240115_beta');
        $delta = $this->migration('20240130_delta');

        // gamma is in history but NOT in finder results
        $gammaId = '20240120_gamma';

        $storage  = new SpyStorage([$alpha->id(), $gammaId, $delta->id()]);
        $finder   = $this->stubFinder([$alpha, $beta, $delta]); // gamma missing from disk
        $executor = new SpyExecutor();

        $runner  = new Orchestrator($storage, $finder, $executor);
        $entries = iterator_to_array($runner->status());

        self::assertCount(4, $entries);

        // IDs sorted ascending: alpha, beta, delta, gamma
        // Wait — sort is lexicographic: '20240101_alpha' < '20240115_beta' < '20240120_gamma' < '20240130_delta'
        self::assertSame($alpha->id(), $entries[0]->id);
        self::assertSame(MigrationState::Applied, $entries[0]->state);
        self::assertNotNull($entries[0]->appliedAt);
        self::assertNotNull($entries[0]->path);

        self::assertSame($beta->id(), $entries[1]->id);
        self::assertSame(MigrationState::Pending, $entries[1]->state);
        self::assertNull($entries[1]->appliedAt);
        self::assertNotNull($entries[1]->path);

        self::assertSame($gammaId, $entries[2]->id);
        self::assertSame(MigrationState::Missing, $entries[2]->state);
        self::assertNotNull($entries[2]->appliedAt);
        self::assertNull($entries[2]->path);

        self::assertSame($delta->id(), $entries[3]->id);
        self::assertSame(MigrationState::Applied, $entries[3]->state);
        self::assertNotNull($entries[3]->appliedAt);
        self::assertNotNull($entries[3]->path);
    }

    // -------------------------------------------------------------------------
    // Reporter (ReportsMigrations)
    // -------------------------------------------------------------------------

    public function testRunReportsStartingAndFinishedForEachMigrationInOrder(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $m2 = $this->migration('20240115_090000_beta');

        $storage = new SpyStorage([]);              // nothing applied yet
        $finder = $this->stubFinder([$m1, $m2]);
        $reporter = new SpyReporter();

        $runner = new Orchestrator($storage, $finder, new SpyExecutor(), $reporter);
        $runner->run();

        self::assertSame(
            [
                ['starting', $m1->id(), Direction::Up],
                ['finished', $m1->id(), Direction::Up],
                ['starting', $m2->id(), Direction::Up],
                ['finished', $m2->id(), Direction::Up],
            ],
            $reporter->events,
        );

        // A duration is measured and reported for each finished migration.
        self::assertCount(2, $reporter->durations);
        foreach ($reporter->durations as $duration) {
            self::assertGreaterThanOrEqual(0.0, $duration);
        }
    }

    public function testRunReportsFailedForTheThrowingMigrationThenReThrows(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $m2 = $this->migration('20240115_090000_beta');

        $storage = new SpyStorage([]);
        $finder = $this->stubFinder([$m1, $m2]);
        $reporter = new SpyReporter();

        // Executor that fails on m2.
        $executor = new class implements ExecutesMigrations {
            public function execute(MigrationFile $migration, Direction $direction): void
            {
                if ($migration->id() === '20240115_090000_beta') {
                    throw new \RuntimeException('boom');
                }
            }
        };

        $runner = new Orchestrator($storage, $finder, $executor, $reporter);

        try {
            $runner->run();
            self::fail('Expected the migration failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(
            [
                ['starting', $m1->id(), Direction::Up],
                ['finished', $m1->id(), Direction::Up],
                ['starting', $m2->id(), Direction::Up],
                ['failed',   $m2->id(), Direction::Up],
            ],
            $reporter->events,
        );
    }

    public function testRollbackReportsInDownDirection(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $m2 = $this->migration('20240115_090000_beta');

        $storage = new SpyStorage([$m1->id(), $m2->id()]);
        $finder = $this->stubFinder([$m1, $m2]);
        $reporter = new SpyReporter();

        $runner = new Orchestrator($storage, $finder, new SpyExecutor(), $reporter);
        $runner->rollback(1);

        self::assertSame(
            [
                ['starting', $m2->id(), Direction::Down],
                ['finished', $m2->id(), Direction::Down],
            ],
            $reporter->events,
        );
    }

    public function testDefaultReporterIsANullReporterAndDoesNotInterfere(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');

        // No reporter argument — the NullReporter default must be used.
        $runner = new Orchestrator(new SpyStorage([]), $this->stubFinder([$m1]), new SpyExecutor());
        $executed = $runner->run();

        self::assertCount(1, $executed);
        self::assertSame($m1->id(), $executed[0]->id());
    }

    public function testPerCallReporterOverridesConstructorReporter(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');

        $constructorReporter = new SpyReporter();
        $callReporter = new SpyReporter();

        $runner = new Orchestrator(
            new SpyStorage([]),
            $this->stubFinder([$m1]),
            new SpyExecutor(),
            $constructorReporter,
        );

        // The per-call reporter must be used for this call...
        $runner->run($callReporter);

        self::assertSame(
            [
                ['starting', $m1->id(), Direction::Up],
                ['finished', $m1->id(), Direction::Up],
            ],
            $callReporter->events,
        );

        // ...and the constructor's reporter must not be touched.
        self::assertSame([], $constructorReporter->events);
    }

    public function testRollbackAcceptsPerCallReporter(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');

        $callReporter = new SpyReporter();
        $runner = new Orchestrator(
            new SpyStorage([$m1->id()]),
            $this->stubFinder([$m1]),
            new SpyExecutor(),
        );

        $runner->rollback(1, $callReporter);

        self::assertSame(
            [
                ['starting', $m1->id(), Direction::Down],
                ['finished', $m1->id(), Direction::Down],
            ],
            $callReporter->events,
        );
    }

    // -------------------------------------------------------------------------
    // RunsMigrations seam
    // -------------------------------------------------------------------------

    public function testOrchestratorImplementsRunsMigrations(): void
    {
        $runner = new Orchestrator(new SpyStorage(), $this->stubFinder([]), new SpyExecutor());

        self::assertInstanceOf(RunsMigrations::class, $runner);
    }

    public function testOrchestratorImplementsRunsMigrationsWithReporter(): void
    {
        $runner = new Orchestrator(new SpyStorage(), $this->stubFinder([]), new SpyExecutor());

        self::assertInstanceOf(\Dakujem\Migrun\RunsMigrationsWithReporter::class, $runner);
        // The reporting-aware contract extends the base one, so it satisfies both.
        self::assertInstanceOf(RunsMigrations::class, $runner);
    }

    /**
     * A decorator typed against RunsMigrationsWithReporter must be able to forward a
     * reporter through the interface — this is the seam that plain RunsMigrations cannot
     * express, because its run()/rollback() do not declare the parameter.
     */
    public function testReporterCanBeForwardedThroughTheReportingInterface(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $reporter = new SpyReporter();

        $inner = new Orchestrator(new SpyStorage([]), $this->stubFinder([$m1]), new SpyExecutor());

        // A minimal pass-through decorator, typed against the reporting-aware contract.
        $decorator = new class($inner) implements \Dakujem\Migrun\RunsMigrationsWithReporter {
            public function __construct(private \Dakujem\Migrun\RunsMigrationsWithReporter $inner) {}

            public function run(?\Dakujem\Migrun\ReportsMigrations $reporter = null): array
            {
                return $this->inner->run($reporter);
            }

            public function runTo(string $id, ?\Dakujem\Migrun\ReportsMigrations $reporter = null): array
            {
                return $this->inner->runTo($id, $reporter);
            }

            public function rollback(int $steps = 1, ?\Dakujem\Migrun\ReportsMigrations $reporter = null): array
            {
                return $this->inner->rollback($steps, $reporter);
            }

            public function rollbackBefore(string $id, ?\Dakujem\Migrun\ReportsMigrations $reporter = null): array
            {
                return $this->inner->rollbackBefore($id, $reporter);
            }

            public function rollbackAll(?\Dakujem\Migrun\ReportsMigrations $reporter = null): array
            {
                return $this->inner->rollbackAll($reporter);
            }

            public function rollbackExactly(array $orderedIds, ?\Dakujem\Migrun\ReportsMigrations $reporter = null): array
            {
                return $this->inner->rollbackExactly($orderedIds, $reporter);
            }

            public function status(): array
            {
                return $this->inner->status();
            }
        };

        $executed = $decorator->run($reporter);

        self::assertCount(1, $executed);
        // The reporter survived the hop through the decorator.
        self::assertSame(
            [
                ['starting', $m1->id(), Direction::Up],
                ['finished', $m1->id(), Direction::Up],
            ],
            $reporter->events,
        );

        // The decorator is still usable wherever the base contract is expected.
        $wrap = static fn(RunsMigrations $r): RunsMigrations => $r;
        self::assertInstanceOf(RunsMigrations::class, $wrap($decorator));
    }

    public function testRunsMigrationsContractIsUsableThroughTheInterfaceType(): void
    {
        $m1 = $this->migration('20240101_120000_alpha');
        $m2 = $this->migration('20240115_090000_beta');

        $storage = new SpyStorage([$m1->id()]); // m1 already applied, m2 pending
        $finder = $this->stubFinder([$m1, $m2]);

        // Bind to the interface, not the concrete class, to exercise the seam.
        $runner = new Orchestrator($storage, $finder, new SpyExecutor());
        self::assertInstanceOf(RunsMigrations::class, $runner);

        $wrap = static fn(RunsMigrations $r): RunsMigrations => $r;
        $interfaceTyped = $wrap($runner);

        $executed = $interfaceTyped->run();
        self::assertCount(1, $executed);
        self::assertSame($m2->id(), $executed[0]->id());

        self::assertSame([], $interfaceTyped->rollback(0));
        self::assertCount(2, $interfaceTyped->status());
    }
}
