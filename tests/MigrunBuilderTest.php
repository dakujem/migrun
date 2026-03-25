<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Executor\ContainerInvoker;
use Dakujem\Migrun\Executor\TrivialInvoker;
use Dakujem\Migrun\Finder\DirectoryFinder;
use Dakujem\Migrun\MigrunBuilder;
use Dakujem\Migrun\Orchestrator;
use Dakujem\Migrun\Storage\JsonFileStorage;
use LogicException;
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

        // Reach into Orchestrator → Executor → invoker
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
    // Storage path resolution
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
            ->storage($file)
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        $storagePath = $this->prop($storage, 'filePath');

        self::assertSame($file, $storagePath);
    }

    public function testNonExistentDirectoryStoragePathGetsMigrunJsonAppended(): void
    {
        $storageDir = $this->dir . '/.migrun'; // does not exist yet

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->storage($storageDir)
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        $storagePath = $this->prop($storage, 'filePath');

        self::assertSame($storageDir . '/migrun.json', $storagePath);
    }

    public function testExistingDirectoryStoragePathGetsMigrunJsonAppended(): void
    {
        $storageDir = $this->dir . '/store';
        mkdir($storageDir);

        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->storage($storageDir)
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        $storagePath = $this->prop($storage, 'filePath');

        self::assertSame($storageDir . '/migrun.json', $storagePath);
    }

    public function testResettingStorageToNullRestoresDefault(): void
    {
        $orchestrator = (new MigrunBuilder())
            ->directory($this->dir)
            ->storage($this->dir . '/custom.json')
            ->storage(null) // reset
            ->build();

        $storage = $this->prop($orchestrator, 'storage');
        $storagePath = $this->prop($storage, 'filePath');

        self::assertSame($this->dir . '/.migrun/migrun.json', $storagePath);
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
            ->directory($this->dir)
            ->recursive(false)
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
        self::assertSame($builder, $builder->storage(null));
        self::assertSame($builder, $builder->recursive(false));
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

        // The directory() setter on the parent must return static (the subclass instance)
        $result = $subclass->recursive(false);
        self::assertInstanceOf($subclass::class, $result);
    }
}
