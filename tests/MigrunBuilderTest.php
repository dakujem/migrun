<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Executor\ContainerInvoker;
use Dakujem\Migrun\Executor\TrivialInvoker;
use Dakujem\Migrun\Finder\DirectoryFinder;
use Dakujem\Migrun\MigrunBuilder;
use Dakujem\Migrun\Orchestrator;
use Dakujem\Migrun\Storage\JsonFileStorage;
use Dakujem\Migrun\Storage\MysqliStorage;
use Dakujem\Migrun\Storage\PdoStorage;
use Dakujem\Migrun\Storage\SqliteStorage;
use LogicException;
use mysqli;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionObject;

final class MigrunBuilderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrun_builder_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    /** Read a private/protected property from an object via reflection. */
    private function prop(object $obj, string $name): mixed
    {
        $ref = new ReflectionObject($obj);
        $prop = $ref->getProperty($name);
        return $prop->getValue($obj);
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Nothing in container.");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    private function inMemoryPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    private function connectOrSkip(): mysqli
    {
        if (!extension_loaded('mysqli')) {
            self::markTestSkipped('mysqli extension is not available.');
        }

        $host = (string) (getenv('MYSQL_HOST') ?: 'localhost');
        $port = (int)    (getenv('MYSQL_PORT') ?: 3306);
        $user = (string) (getenv('MYSQL_USER') ?: 'root');
        $pass = (string) (getenv('MYSQL_PASS') ?: '');
        $db   = (string) (getenv('MYSQL_DB')   ?: 'migrun_test');

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

    // -------------------------------------------------------------------------
    // build() — missing directory
    // -------------------------------------------------------------------------

    public function testBuildThrowsWhenDirectoryNotSet(): void
    {
        $this->expectException(LogicException::class);
        (new MigrunBuilder())->build();
    }

    // -------------------------------------------------------------------------
    // build() — returns correct types
    // -------------------------------------------------------------------------

    public function testBuildReturnsOrchestrator(): void
    {
        $result = (new MigrunBuilder())
            ->directory($this->dir)
            ->build();

        self::assertInstanceOf(Orchestrator::class, $result);
    }

    // -------------------------------------------------------------------------
    // Invoker selection
    // -------------------------------------------------------------------------

    public function testNoContainerUsesTrivialInvoker(): void
    {
        $builder = (new MigrunBuilder())->directory($this->dir);
        $orchestrator = $builder->build();

        $executor = $this->prop($orchestrator, 'executor');
        $invoker = $this->prop($executor, 'invoker');

        self::assertInstanceOf(TrivialInvoker::class, $invoker);
    }

    public function testWithContainerUsesContainerInvoker(): void
    {
        $builder = (new MigrunBuilder())
            ->directory($this->dir)
            ->container($this->emptyContainer());
        $orchestrator = $builder->build();

        $executor = $this->prop($orchestrator, 'executor');
        $invoker = $this->prop($executor, 'invoker');

        self::assertInstanceOf(ContainerInvoker::class, $invoker);
    }

    public function testResettingContainerToNullRevertToTrivialInvoker(): void
    {
        $builder = (new MigrunBuilder())
            ->directory($this->dir)
            ->container($this->emptyContainer())
            ->container(null); // reset

        $orchestrator = $builder->build();

        $executor = $this->prop($orchestrator, 'executor');
        $invoker = $this->prop($executor, 'invoker');

        self::assertInstanceOf(TrivialInvoker::class, $invoker);
    }

    // -------------------------------------------------------------------------
    // Storage — JSON (fileStorage)
    // -------------------------------------------------------------------------

    public function testDefaultStoragePathIsInsideMigrationsDir(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(JsonFileStorage::class, $storage);

        $storagePath = $this->prop($storage, 'filePath');
        self::assertSame(
            $this->dir . '/.migrun/migrun.json',
            $storagePath,
        );
    }

    public function testExplicitFilePathIsUsedAsIs(): void
    {
        $file = $this->dir . '/custom.json';

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->fileStorage($file)
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(JsonFileStorage::class, $storage);
        self::assertSame($file, $this->prop($storage, 'filePath'));
    }

    public function testNonExistentDirectoryStoragePathGetsMigrunJsonAppended(): void
    {
        $storageDir = $this->dir . '/.migrun'; // does not exist yet

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->fileStorage($storageDir)
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertSame($storageDir . '/migrun.json', $this->prop($storage, 'filePath'));
    }

    public function testExistingDirectoryStoragePathGetsMigrunJsonAppended(): void
    {
        $storageDir = $this->dir . '/store';
        mkdir($storageDir);

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->fileStorage($storageDir)
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertSame($storageDir . '/migrun.json', $this->prop($storage, 'filePath'));
    }

    public function testResettingStoragePathToNullRestoresDefault(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->fileStorage($this->dir . '/custom.json')
            ->fileStorage(null) // reset
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(JsonFileStorage::class, $storage);
        self::assertSame($this->dir . '/.migrun/migrun.json', $this->prop($storage, 'filePath'));
    }

    // -------------------------------------------------------------------------
    // Storage — SQLite
    // -------------------------------------------------------------------------

    public function testSqliteDefaultPathIsInsideMigrationsDir(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->sqliteStorage()
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(SqliteStorage::class, $storage);
    }

    public function testSqliteExplicitPath(): void
    {
        $path = $this->dir . '/history.sqlite';

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->sqliteStorage($path)
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(SqliteStorage::class, $storage);
    }

    public function testSqliteResetsWhenCalledWithNull(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->sqliteStorage()
            ->sqliteStorage(null) // reset
            ->build();

        // Falls back to the default JSON storage.
        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(JsonFileStorage::class, $storage);
    }

    // -------------------------------------------------------------------------
    // Storage — PDO
    // -------------------------------------------------------------------------

    public function testPdoStorageIsUsedWhenPdoIsSet(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->pdoStorage($this->inMemoryPdo())
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(PdoStorage::class, $storage);
    }

    public function testPdoCustomTableName(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->pdoStorage($this->inMemoryPdo(), table: 'schema_history')
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(PdoStorage::class, $storage);
        self::assertSame('schema_history', $this->prop($storage, 'table'));
    }

    public function testPdoResetsWhenCalledWithNull(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->pdoStorage($this->inMemoryPdo())
            ->pdoStorage(null) // reset
            ->build();

        // Falls back to the default JSON storage.
        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(JsonFileStorage::class, $storage);
    }

    // -------------------------------------------------------------------------
    // Storage — mysqli
    // -------------------------------------------------------------------------

    public function testMysqliStorageIsUsedWhenMysqliIsSet(): void
    {
        $conn = $this->connectOrSkip();

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->mysqliStorage($conn)
            ->build();

        $conn->close();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(MysqliStorage::class, $storage);
    }

    public function testMysqliCustomTableName(): void
    {
        $conn = $this->connectOrSkip();

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->mysqliStorage($conn, table: 'schema_history')
            ->build();

        $conn->close();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(MysqliStorage::class, $storage);
        self::assertSame('schema_history', $this->prop($storage, 'table'));
    }

    public function testMysqliResetsWhenCalledWithNull(): void
    {
        $conn = $this->connectOrSkip();

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->mysqliStorage($conn)
            ->mysqliStorage(null) // reset
            ->build();

        $conn->close();

        // Falls back to the default JSON storage.
        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(JsonFileStorage::class, $storage);
    }

    // -------------------------------------------------------------------------
    // Storage — conflict detection
    // -------------------------------------------------------------------------

    public function testStoragePathAndPdoTogetherThrowsOnBuild(): void
    {
        $this->expectException(LogicException::class);

        (new MigrunBuilder())
            ->directory($this->dir)
            ->fileStorage($this->dir . '/migrun.json')
            ->pdoStorage($this->inMemoryPdo())
            ->build();
    }

    public function testStoragePathAndSqliteTogetherThrowsOnBuild(): void
    {
        $this->expectException(LogicException::class);

        (new MigrunBuilder())
            ->directory($this->dir)
            ->fileStorage($this->dir . '/migrun.json')
            ->sqliteStorage()
            ->build();
    }

    public function testPdoAndSqliteTogetherThrowsOnBuild(): void
    {
        $this->expectException(LogicException::class);

        (new MigrunBuilder())
            ->directory($this->dir)
            ->pdoStorage($this->inMemoryPdo())
            ->sqliteStorage()
            ->build();
    }

    public function testMysqliAndFileStorageTogetherThrowsOnBuild(): void
    {
        $conn = $this->connectOrSkip();

        $this->expectException(LogicException::class);

        (new MigrunBuilder())
            ->directory($this->dir)
            ->mysqliStorage($conn)
            ->fileStorage($this->dir . '/migrun.json')
            ->build();
    }

    public function testMysqliAndPdoTogetherThrowsOnBuild(): void
    {
        $conn = $this->connectOrSkip();

        $this->expectException(LogicException::class);

        (new MigrunBuilder())
            ->directory($this->dir)
            ->mysqliStorage($conn)
            ->pdoStorage($this->inMemoryPdo())
            ->build();
    }

    public function testMysqliAndSqliteTogetherThrowsOnBuild(): void
    {
        $conn = $this->connectOrSkip();

        $this->expectException(LogicException::class);

        (new MigrunBuilder())
            ->directory($this->dir)
            ->mysqliStorage($conn)
            ->sqliteStorage()
            ->build();
    }

    // -------------------------------------------------------------------------
    // Reporter
    // -------------------------------------------------------------------------

    public function testDefaultReporterIsNullReporter(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->build();

        self::assertInstanceOf(
            \Dakujem\Migrun\NullReporter::class,
            $this->prop($orchestrator, 'reporter'),
        );
    }

    public function testConfiguredReporterIsWiredIntoOrchestrator(): void
    {
        $reporter = new \Dakujem\Migrun\NullReporter();

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->reporter($reporter)
            ->build();

        self::assertSame($reporter, $this->prop($orchestrator, 'reporter'));
    }

    public function testResettingReporterToNullRestoresNullReporter(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->reporter(new \Dakujem\Migrun\NullReporter())
            ->reporter(null) // reset
            ->build();

        self::assertInstanceOf(
            \Dakujem\Migrun\NullReporter::class,
            $this->prop($orchestrator, 'reporter'),
        );
    }

    // -------------------------------------------------------------------------
    // Ordering
    // -------------------------------------------------------------------------

    public function testDefaultOrderIsLexicographic(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->build();

        self::assertInstanceOf(
            \Dakujem\Migrun\LexicographicOrder::class,
            $this->prop($orchestrator, 'order'),
        );
    }

    /** The finder and the orchestrator must share one comparison, or orders could drift. */
    public function testConfiguredOrderIsWiredIntoBothFinderAndOrchestrator(): void
    {
        $order = new \Dakujem\Migrun\NumericOrder();

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->ordering($order)
            ->build();

        self::assertSame($order, $this->prop($orchestrator, 'order'));
        self::assertSame($order, $this->prop($this->prop($orchestrator, 'finder'), 'order'));
    }

    public function testResettingOrderToNullRestoresLexicographic(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->ordering(new \Dakujem\Migrun\NumericOrder())
            ->ordering(null) // reset
            ->build();

        self::assertInstanceOf(
            \Dakujem\Migrun\LexicographicOrder::class,
            $this->prop($orchestrator, 'order'),
        );
    }

    // -------------------------------------------------------------------------
    // Finder configuration
    // -------------------------------------------------------------------------

    public function testFinderUsesConfiguredDirectory(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->build();

        $finder = $this->prop($orchestrator, 'finder');
        self::assertInstanceOf(DirectoryFinder::class, $finder);

        $finderDir = $this->prop($finder, 'directory');
        self::assertSame($this->dir, $finderDir);
    }

    public function testRecursiveDefaultIsTrue(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->build();

        $finder = $this->prop($orchestrator, 'finder');
        self::assertTrue($this->prop($finder, 'recursive'));
    }

    public function testRecursiveCanBeDisabled(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir, recursive: false)
            ->build();

        $finder = $this->prop($orchestrator, 'finder');
        self::assertFalse($this->prop($finder, 'recursive'));
    }

    public function testResettingDirectoryToNullCausesThrowOnBuild(): void
    {
        $this->expectException(LogicException::class);

        (new MigrunBuilder())
            ->directory($this->dir)
            ->directory(null) // reset
            ->build();
    }

    // -------------------------------------------------------------------------
    // Fluent chaining returns same instance
    // -------------------------------------------------------------------------

    public function testSettersReturnSameInstance(): void
    {
        $builder = new MigrunBuilder();

        self::assertSame($builder, $builder->directory($this->dir));
        self::assertSame($builder, $builder->container(null));
        self::assertSame($builder, $builder->reporter(null));
        self::assertSame($builder, $builder->ordering(null));
        self::assertSame($builder, $builder->fileStorage(null));
        self::assertSame($builder, $builder->sqliteStorage(null));
        self::assertSame($builder, $builder->pdoStorage(null));
        self::assertSame($builder, $builder->mysqliStorage(null));
    }

    // -------------------------------------------------------------------------
    // Builder is mutable — second build() picks up later changes
    // -------------------------------------------------------------------------

    public function testBuilderIsMutableAndBuildCanBeCalledTwice(): void
    {
        $builder = (new MigrunBuilder())->directory($this->dir);

        $first = $builder->build();

        $dir2 = $this->dir . '/sub';
        mkdir($dir2);
        $builder->directory($dir2);

        $second = $builder->build();

        $finderFirst = $this->prop($first, 'finder');
        $finderSecond = $this->prop($second, 'finder');

        self::assertSame($this->dir, $this->prop($finderFirst, 'directory'));
        self::assertSame($dir2, $this->prop($finderSecond, 'directory'));
    }

    // -------------------------------------------------------------------------
    // Subclassing
    // -------------------------------------------------------------------------

    public function testSubclassCanBakeInDefaults(): void
    {
        $dir = $this->dir;

        $subclass = new class($dir) extends MigrunBuilder {
            public function __construct(string $dir)
            {
                // Subclasses can still set $recursive directly.
                $this->directory = $dir;
                $this->recursive = false;
            }
        };

        $orchestrator = $subclass->build();

        $finder = $this->prop($orchestrator, 'finder');
        self::assertSame($dir, $this->prop($finder, 'directory'));
        self::assertFalse($this->prop($finder, 'recursive'));
    }

    public function testSubclassSetterReturnsSubclassInstance(): void
    {
        $dir = $this->dir;

        $subclass = new class($dir) extends MigrunBuilder {
            public function __construct(string $dir)
            {
                $this->directory = $dir;
            }

            public function custom(string $value): static
            {
                return $this;
            }
        };

        $result = $subclass->directory($dir, recursive: false);
        self::assertInstanceOf($subclass::class, $result);
    }

    // -------------------------------------------------------------------------
    // Storage — mysqli with mock (no live MySQL required)
    // -------------------------------------------------------------------------

    public function testMysqliStorageIsBuiltWithMockConnection(): void
    {
        $conn = $this->createMock(mysqli::class);

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->mysqliStorage($conn)
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        self::assertInstanceOf(MysqliStorage::class, $storage);
    }
}
