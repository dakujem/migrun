<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Direction;
use Dakujem\Migrun\Exception\MigrationNotAppliedException;
use Dakujem\Migrun\Exception\MigrationNotFoundException;
use Dakujem\Migrun\ExecutesMigrations;
use Dakujem\Migrun\Finder\DirectoryFinder;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationRun;
use Dakujem\Migrun\Orchestrator;
use Dakujem\Migrun\Storage\JsonFileStorage;
use PHPUnit\Framework\TestCase;

/**
 * Targeted run and rollback: runTo(), rollbackBefore(), rollbackAll(), rollbackExactly().
 *
 * Two rules under test throughout:
 *   - the migration you name is always acted upon;
 *   - rollbacks sequence in reverse APPLICATION order, except rollbackExactly(),
 *     which follows the caller.
 */
final class TargetedRunTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrun_targeted_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->dir);
    }

    private function rmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->rmdir($full) : unlink($full);
        }
        rmdir($path);
    }

    /** @param string[] $ids */
    private function makeFiles(array $ids): void
    {
        foreach ($ids as $id) {
            file_put_contents($this->dir . "/{$id}.php", '<?php return function () {};');
        }
    }

    private function storage(): JsonFileStorage
    {
        return new JsonFileStorage($this->dir . '/.migrun/history.json');
    }

    private function executor(): ExecutesMigrations
    {
        return new class implements ExecutesMigrations {
            /** @var array<int, array{0:string, 1:Direction}> */
            public array $calls = [];

            public function execute(MigrationFile $migration, Direction $direction): void
            {
                $this->calls[] = [$migration->id(), $direction];
            }
        };
    }

    /** @return string[] */
    private function ids(array $runs): array
    {
        return array_map(fn(MigrationRun $r) => $r->id(), $runs);
    }

    // -------------------------------------------------------------------------
    // runTo()
    // -------------------------------------------------------------------------

    public function testRunToAppliesUpToAndIncludingTheTarget(): void
    {
        $this->makeFiles(['0001_a', '0002_b', '0003_c', '0004_d']);
        $runner = new Orchestrator($this->storage(), new DirectoryFinder($this->dir), $this->executor());

        $executed = $runner->runTo('0003_c');

        // The named migration is applied; later ones stay pending.
        self::assertSame(['0001_a', '0002_b', '0003_c'], $this->ids($executed));
    }

    public function testRunToLeavesLaterMigrationsPendingForALaterRun(): void
    {
        $this->makeFiles(['0001_a', '0002_b', '0003_c']);
        $storage = $this->storage();
        $runner = new Orchestrator($storage, new DirectoryFinder($this->dir), $this->executor());

        $runner->runTo('0001_a');
        self::assertTrue($storage->isApplied('0001_a'));
        self::assertFalse($storage->isApplied('0002_b'));

        // A plain run() then picks up the remainder.
        self::assertSame(['0002_b', '0003_c'], $this->ids($runner->run()));
    }

    public function testRunToIsIdempotentWhenTheTargetIsAlreadyApplied(): void
    {
        $this->makeFiles(['0001_a', '0002_b']);
        $runner = new Orchestrator($this->storage(), new DirectoryFinder($this->dir), $this->executor());

        $runner->runTo('0001_a');

        self::assertSame([], $this->ids($runner->runTo('0001_a')));
    }

    public function testRunToThrowsForAnUnknownTarget(): void
    {
        $this->makeFiles(['0001_a']);
        $runner = new Orchestrator($this->storage(), new DirectoryFinder($this->dir), $this->executor());

        // A truncated or mistyped ID must not silently run a different set.
        $this->expectException(MigrationNotFoundException::class);
        $runner->runTo('0002');
    }

    // -------------------------------------------------------------------------
    // rollbackBefore()
    // -------------------------------------------------------------------------

    public function testRollbackBeforeRevertsTheTargetAndEverythingAfterIt(): void
    {
        $this->makeFiles(['0001_a', '0002_b', '0003_c', '0004_d']);
        $storage = $this->storage();
        $executor = $this->executor();
        $runner = new Orchestrator($storage, new DirectoryFinder($this->dir), $executor);
        $runner->run();

        $reverted = $runner->rollbackBefore('0002_b');

        // Inclusive: the named migration is reverted too. Newest first.
        self::assertSame(['0004_d', '0003_c', '0002_b'], $this->ids($reverted));
        self::assertTrue($storage->isApplied('0001_a'));
        self::assertFalse($storage->isApplied('0002_b'));
    }

    public function testRollbackBeforeTheNewestRevertsExactlyThatOne(): void
    {
        // The common UI case: picking the last row must not be a no-op.
        $this->makeFiles(['0001_a', '0002_b', '0003_c']);
        $runner = new Orchestrator($this->storage(), new DirectoryFinder($this->dir), $this->executor());
        $runner->run();

        self::assertSame(['0003_c'], $this->ids($runner->rollbackBefore('0003_c')));
    }

    public function testRollbackBeforeThrowsWhenTargetIsNotApplied(): void
    {
        $this->makeFiles(['0001_a', '0002_b']);
        $runner = new Orchestrator($this->storage(), new DirectoryFinder($this->dir), $this->executor());
        $runner->runTo('0001_a'); // 0002_b left pending

        $this->expectException(MigrationNotAppliedException::class);
        $runner->rollbackBefore('0002_b');
    }

    /**
     * The set is chosen by ID, but the sequence is reverse-application order. With a
     * migration applied out of order, those differ — and application order wins.
     */
    public function testRollbackBeforeSelectsByIdButSequencesByApplicationOrder(): void
    {
        $this->makeFiles(['0001_a', '0002_branch', '0003_c']);
        $storage = $this->storage();
        $executor = $this->executor();
        $runner = new Orchestrator($storage, new DirectoryFinder($this->dir), $executor);

        // 0003 applied before 0002 — as if 0002 arrived later via a branch merge.
        $storage->markApplied('0001_a', new \DateTimeImmutable('2026-07-01 09:00:00'));
        $storage->markApplied('0003_c', new \DateTimeImmutable('2026-07-05 11:30:00'));
        $storage->markApplied('0002_branch', new \DateTimeImmutable('2026-07-28 14:22:10'));

        $reverted = $runner->rollbackBefore('0002_branch');

        // Set is {0002, 0003} by ID; order is 0002 first because it was applied last.
        self::assertSame(['0002_branch', '0003_c'], $this->ids($reverted));
        self::assertTrue($storage->isApplied('0001_a'));
    }

    // -------------------------------------------------------------------------
    // rollbackAll()
    // -------------------------------------------------------------------------

    public function testRollbackAllRevertsEverythingNewestFirst(): void
    {
        $this->makeFiles(['0001_a', '0002_b', '0003_c']);
        $storage = $this->storage();
        $runner = new Orchestrator($storage, new DirectoryFinder($this->dir), $this->executor());
        $runner->run();

        $reverted = $runner->rollbackAll();

        self::assertSame(['0003_c', '0002_b', '0001_a'], $this->ids($reverted));
        foreach (['0001_a', '0002_b', '0003_c'] as $id) {
            self::assertFalse($storage->isApplied($id));
        }
    }

    public function testRollbackAllOnAnEmptyHistoryDoesNothing(): void
    {
        $this->makeFiles(['0001_a']);
        $runner = new Orchestrator($this->storage(), new DirectoryFinder($this->dir), $this->executor());

        self::assertSame([], $runner->rollbackAll());
    }

    // -------------------------------------------------------------------------
    // rollbackExactly()
    // -------------------------------------------------------------------------

    /**
     * The motivating case: revert one migration out of the middle of the history,
     * leaving newer ones applied.
     */
    public function testRollbackExactlyRevertsASingleMigrationOutOfTheMiddle(): void
    {
        $this->makeFiles(['0001_a', '0002_branch', '0003_c']);
        $storage = $this->storage();
        $runner = new Orchestrator($storage, new DirectoryFinder($this->dir), $this->executor());
        $runner->run();

        $reverted = $runner->rollbackExactly(['0002_branch']);

        self::assertSame(['0002_branch'], $this->ids($reverted));
        // A deliberate gap: the newer migration stays applied.
        self::assertTrue($storage->isApplied('0001_a'));
        self::assertFalse($storage->isApplied('0002_branch'));
        self::assertTrue($storage->isApplied('0003_c'));
    }

    public function testRollbackExactlyHonoursTheGivenOrder(): void
    {
        $this->makeFiles(['0001_a', '0002_b', '0003_c']);
        $executor = $this->executor();
        $runner = new Orchestrator($this->storage(), new DirectoryFinder($this->dir), $executor);
        $runner->run();

        // Deliberately not application order — the caller drives.
        $runner->rollbackExactly(['0002_b', '0003_c', '0001_a']);

        self::assertSame(
            ['0002_b', '0003_c', '0001_a'],
            array_values(array_map(
                fn(array $c) => $c[0],
                array_filter($executor->calls, fn(array $c) => $c[1] === Direction::Down),
            )),
        );
    }

    public function testRollbackExactlyIgnoresDuplicates(): void
    {
        $this->makeFiles(['0001_a', '0002_b']);
        $storage = $this->storage();
        $runner = new Orchestrator($storage, new DirectoryFinder($this->dir), $this->executor());
        $runner->run();

        $reverted = $runner->rollbackExactly(['0002_b', '0002_b', '0001_a']);

        self::assertSame(['0002_b', '0001_a'], $this->ids($reverted));
    }

    public function testRollbackExactlyThrowsWhenAnyMigrationIsNotApplied(): void
    {
        $this->makeFiles(['0001_a', '0002_b']);
        $storage = $this->storage();
        $executor = $this->executor();
        $runner = new Orchestrator($storage, new DirectoryFinder($this->dir), $executor);
        $runner->runTo('0001_a'); // 0002_b pending

        try {
            $runner->rollbackExactly(['0001_a', '0002_b']);
            self::fail('Expected MigrationNotAppliedException.');
        } catch (MigrationNotAppliedException) {
            // The whole set is validated up front, so nothing may have been reverted.
            self::assertTrue($storage->isApplied('0001_a'));
            self::assertSame([], array_filter($executor->calls, fn(array $c) => $c[1] === Direction::Down));
        }
    }

    /**
     * The documented UI recipe: a status listing is ascending by ID, which is the
     * opposite of a sensible rollback order, so it must be reversed.
     */
    public function testReversedStatusSliceProducesNewestFirstRollback(): void
    {
        $this->makeFiles(['0001_a', '0002_b', '0003_c']);
        $runner = new Orchestrator($this->storage(), new DirectoryFinder($this->dir), $this->executor());
        $runner->run();

        // As displayed, top to bottom.
        $displayed = ['0002_b', '0003_c'];

        $reverted = $runner->rollbackExactly(array_reverse($displayed));

        self::assertSame(['0003_c', '0002_b'], $this->ids($reverted));
    }
}
