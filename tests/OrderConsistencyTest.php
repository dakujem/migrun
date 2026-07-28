<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Direction;
use Dakujem\Migrun\ExecutesMigrations;
use Dakujem\Migrun\Finder\DirectoryFinder;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationRun;
use Dakujem\Migrun\MigrationStatusEntry;
use Dakujem\Migrun\Orchestrator;
use Dakujem\Migrun\Storage\JsonFileStorage;
use Dakujem\Migrun\Storage\PdoStorage;
use Dakujem\Migrun\Storage\SqliteStorage;
use Dakujem\Migrun\TracksMigrations;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Run order, rollback order and status order must agree with each other, and must be
 * identical across storage backends.
 *
 * Before 1.0.1 they could not: the finder compared IDs with `<=>`, status() sorted with
 * sort(), and each storage imposed its own history order (SQL by collation, the JSON
 * file by insertion order). The orchestrator now sorts everything it consumes itself,
 * comparing IDs byte by byte.
 */
final class OrderConsistencyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrun_consistency_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->dir);
    }

    /** scandir rather than glob, so hidden entries such as .migrun/ are removed too. */
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

    private function inMemoryPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** @return iterable<string, TracksMigrations> */
    private function storages(): iterable
    {
        yield 'json' => new JsonFileStorage($this->dir . '/.migrun/history.json');
        yield 'pdo' => new PdoStorage($this->inMemoryPdo());
        yield 'sqlite' => new SqliteStorage($this->dir . '/.migrun/history.sqlite');
    }

    private function recordingExecutor(): ExecutesMigrations
    {
        return new class implements ExecutesMigrations {
            /** @var array<int, string> */
            public array $ids = [];

            public function execute(MigrationFile $migration, Direction $direction): void
            {
                $this->ids[] = $migration->id();
            }
        };
    }

    // -------------------------------------------------------------------------

    /**
     * The same set of migrations must run in the same order, and roll back in the exact
     * reverse of that order, on every storage backend.
     */
    public function testRunAndRollbackOrderIsIdenticalAcrossStorageBackends(): void
    {
        // Deliberately includes ids whose byte order differs from their numeric order.
        $ids = ['001_a', '002_b', '009_c', '010_d', '20240101_120000_e'];
        $this->makeFiles($ids);

        $runOrders = [];
        $rollbackOrders = [];

        foreach ($this->storages() as $name => $storage) {
            $executor = $this->recordingExecutor();
            $runner = new Orchestrator($storage, new DirectoryFinder($this->dir), $executor);

            $runner->run();
            $runOrders[$name] = $executor->ids;

            $executor->ids = [];
            $runner->rollback(count($ids));
            $rollbackOrders[$name] = $executor->ids;
        }

        // Every backend agrees on run order...
        foreach ($runOrders as $name => $order) {
            self::assertSame($ids, $order, "run order differs for storage '{$name}'");
        }

        // ...and rollback is its exact reverse.
        foreach ($rollbackOrders as $name => $order) {
            self::assertSame(array_reverse($ids), $order, "rollback order differs for storage '{$name}'");
        }
    }

    /**
     * status() must list migrations in the same order the finder would run them.
     */
    public function testStatusOrderMatchesRunOrder(): void
    {
        $ids = ['001_a', '010_b', '009_c', '20240101_120000_d'];
        $this->makeFiles($ids);

        $executor = $this->recordingExecutor();
        $runner = new Orchestrator(
            new JsonFileStorage($this->dir . '/.migrun/history.json'),
            new DirectoryFinder($this->dir),
            $executor,
        );

        $statusOrder = array_map(fn(MigrationStatusEntry $e) => $e->id, $runner->status());
        $runner->run();

        self::assertSame($executor->ids, $statusOrder);
        self::assertSame(['001_a', '009_c', '010_b', '20240101_120000_d'], $statusOrder);
    }

    /**
     * Pure-numeric IDs used to break status() outright: PHP casts numeric-string array
     * keys to int, and MigrationStatusEntry::$id is declared string, so building the
     * report raised a TypeError under strict_types.
     */
    public function testStatusHandlesPureNumericIdsWithoutTypeError(): void
    {
        $this->makeFiles(['9', '10', '09']);

        $runner = new Orchestrator(
            new JsonFileStorage($this->dir . '/.migrun/history.json'),
            new DirectoryFinder($this->dir),
            $this->recordingExecutor(),
        );

        $entries = $runner->status();

        self::assertCount(3, $entries);
        foreach ($entries as $entry) {
            self::assertIsString($entry->id);
        }
        self::assertSame(['09', '10', '9'], array_map(fn($e) => $e->id, $entries));
    }

    /**
     * Migrations applied within the same second share a timestamp, so the ID comparison
     * is what actually decides rollback order. It must be the injected one.
     */
    public function testRollbackOrderFallsBackToTheIdComparisonWithinTheSameSecond(): void
    {
        $ids = ['001_a', '002_b', '009_c', '010_d'];
        $this->makeFiles($ids);

        $storage = new JsonFileStorage($this->dir . '/.migrun/history.json');
        $at = new \DateTimeImmutable('2024-06-01 12:00:00');
        foreach ($ids as $id) {
            $storage->markApplied($id, $at); // identical timestamp for all
        }

        $executor = $this->recordingExecutor();
        (new Orchestrator($storage, new DirectoryFinder($this->dir), $executor))->rollback(count($ids));

        self::assertSame(array_reverse($ids), $executor->ids);
    }

    /**
     * The orchestrator owns ordering: a finder that returns files in some other order
     * must not be able to put run order out of step with rollback and status order.
     */
    public function testFinderOrderCannotOverrideTheOrchestratorsOrder(): void
    {
        $ids = ['001_a', '002_b', '009_c', '010_d'];
        $this->makeFiles($ids);

        // A finder that deliberately hands back files in the worst possible order.
        $shuffling = new class ($this->dir) implements \Dakujem\Migrun\DiscoversMigrations {
            public function __construct(private string $dir) {}

            public function list(): iterable
            {
                $files = iterator_to_array((new DirectoryFinder($this->dir))->list(), false);
                return array_reverse($files); // descending — the opposite of run order
            }

            public function find(array $migrations): array
            {
                return (new DirectoryFinder($this->dir))->find($migrations);
            }
        };

        $executor = $this->recordingExecutor();
        $runner = new Orchestrator(
            new JsonFileStorage($this->dir . '/.migrun/history.json'),
            $shuffling,
            $executor,
        );

        $statusOrder = array_map(fn(MigrationStatusEntry $e) => $e->id, $runner->status());
        $executed = $runner->run();

        // Ascending regardless of what the finder returned.
        self::assertSame($ids, array_map(fn(MigrationRun $r) => $r->id(), $executed));
        self::assertSame($ids, $executor->ids);
        self::assertSame($ids, $statusOrder);

        $executor->ids = [];
        $runner->rollback(count($ids));
        self::assertSame(array_reverse($ids), $executor->ids);
    }
}
