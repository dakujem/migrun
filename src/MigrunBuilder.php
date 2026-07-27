<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use Dakujem\Migrun\Executor\ContainerInvoker;
use Dakujem\Migrun\Executor\Executor;
use Dakujem\Migrun\Executor\TrivialInvoker;
use Dakujem\Migrun\Finder\DirectoryFinder;
use Dakujem\Migrun\Storage\JsonFileStorage;
use Dakujem\Migrun\Storage\MysqliStorage;
use Dakujem\Migrun\Storage\PdoStorage;
use Dakujem\Migrun\Storage\SqliteStorage;
use LogicException;
use mysqli;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Convenience builder for the most common Orchestrator setup.
 *
 * Only the migrations directory is required; everything else has a sensible
 * default. Setters are mutable and return $this (as static) for chaining.
 * Passing null to any setter resets that slot back to its default.
 *
 * Minimal setup:
 *
 *   $orchestrator = (new MigrunBuilder())
 *       ->directory(__DIR__ . '/migrations')
 *       ->build();
 *
 * With a PSR-11 container for autowired migrations:
 *
 *   $orchestrator = (new MigrunBuilder())
 *       ->directory(__DIR__ . '/migrations')
 *       ->container($container)
 *       ->build();
 * Storage backends (mutually exclusive — build() throws if more than one is set):
 *
 *   ->fileStorage('/path/to/migrun.json')   JSON file (default when nothing is set)
 *   ->sqliteStorage()                        SQLite at {migrations-dir}/.migrun/migrun.sqlite
 *   ->sqliteStorage('/path/to/history.sqlite') SQLite at an explicit path
 *   ->pdoStorage($pdo)                       any PDO connection, default table name
 *   ->pdoStorage($pdo, table: 'schema_history') any PDO connection, custom table name
 *   ->mysqliStorage($mysqli)                 MySQL/MariaDB via mysqli, default table name
 *   ->mysqliStorage($mysqli, table: 'schema_history') mysqli with custom table name
 *
 * The class is not final and may be extended to add project-specific defaults
 * or additional fluent setters.
 */
class MigrunBuilder
{
    protected ?string $directory = null;
    protected ?ContainerInterface $container = null;
    protected bool $recursive = true;
    protected ?ReportsMigrations $reporter = null;

    // Storage slots — at most one may be non-null when build() is called.
    protected ?string $storagePath = null;
    protected ?PDO $pdo = null;
    protected string $pdoTable = 'migrun_migrations';
    protected ?mysqli $mysqli = null;
    protected string $mysqliTable = 'migrun_migrations';
    /**
     * null  = not set (slot is clear)
     * false = use default path ({migrations-dir}/.migrun/migrun.sqlite)
     * string = explicit path
     */
    protected string|false|null $sqlitePath = null;
    protected string $sqliteTable = 'migrun_migrations';

    /**
     * Path to the directory that holds migration files.
     * Required — build() throws if this is not set.
     * Pass null to unset.
     *
     * Set $recursive to false to disable subdirectory scanning (default: true).
     */
    public function directory(?string $directory, bool $recursive = true): static
    {
        $this->directory = $directory;
        $this->recursive = $recursive;
        return $this;
    }

    /**
     * PSR-11 container used for autowiring migration dependencies.
     * When set, a ContainerInvoker is used; otherwise a TrivialInvoker (no autowiring).
     * Pass null to revert to no-container mode.
     */
    public function container(?ContainerInterface $container): static
    {
        $this->container = $container;
        return $this;
    }

    /**
     * Reporter that observes the run/rollback as it happens, for live progress output.
     *
     * When omitted (or reset to null), a NullReporter is used and nothing is reported;
     * run() and rollback() simply return their result arrays as before.
     *
     * See ReportsMigrations for the contract, and NullReporter for a base class to
     * extend when you only want to react to some of the events.
     */
    public function reporter(?ReportsMigrations $reporter): static
    {
        $this->reporter = $reporter;
        return $this;
    }

    /**
     * Use a JSON file as the migration history storage.
     *
     * - File path  → used as-is.
     * - Directory path → migrun.json is appended as the filename.
     * - null (default) → {migrations-dir}/.migrun/migrun.json.
     *
     * Mutually exclusive with pdoStorage() and sqliteStorage() — build() throws if more
     * than one storage method is configured at once.
     */
    public function fileStorage(?string $path): static
    {
        $this->storagePath = $path;
        return $this;
    }

    /**
     * Use an SQLite database file as the migration history storage.
     *
     * - No path argument → {migrations-dir}/.migrun/migrun.sqlite.
     * - Explicit path → used as-is (parent directory is created if needed).
     * - Pass null to clear this slot.
     *
     * Mutually exclusive with fileStorage() and pdoStorage() — build() throws if
     * more than one storage method is configured at once.
     */
    public function sqliteStorage(string|false|null $path = false, string $table = 'migrun_migrations'): static
    {
        $this->sqlitePath = $path;
        $this->sqliteTable = $table;
        return $this;
    }

    /**
     * Use a PDO connection as the migration history storage.
     *
     * Pass null to clear this slot.
     *
     * Mutually exclusive with fileStorage(), sqliteStorage(), and mysqliStorage() — build() throws if
     * more than one storage method is configured at once.
     */
    public function pdoStorage(?PDO $pdo, string $table = 'migrun_migrations'): static
    {
        $this->pdo = $pdo;
        $this->pdoTable = $table;
        return $this;
    }

    /**
     * Use a mysqli connection as the migration history storage (MySQL/MariaDB).
     *
     * Pass null to clear this slot.
     *
     * Mutually exclusive with fileStorage(), sqliteStorage(), and pdoStorage() — build() throws if
     * more than one storage method is configured at once.
     */
    public function mysqliStorage(?mysqli $mysqli, string $table = 'migrun_migrations'): static
    {
        $this->mysqli = $mysqli;
        $this->mysqliTable = $table;
        return $this;
    }

    /**
     * Build and return a fully wired Orchestrator.
     *
     * @throws LogicException if no migrations directory has been set, or if
     *                        more than one storage backend is configured.
     */
    public function build(): Orchestrator
    {
        if ($this->directory === null) {
            throw new LogicException('A migrations directory must be set via directory() before calling build().');
        }

        $storage = $this->buildStorage();
        $finder = new DirectoryFinder($this->directory, $this->recursive);
        $invoker = $this->container !== null
            ? new ContainerInvoker($this->container)
            : new TrivialInvoker();
        $executor = new Executor($invoker);

        return new Orchestrator(
            $storage,
            $finder,
            $executor,
            $this->reporter ?? new NullReporter(),
        );
    }

    // -------------------------------------------------------------------------

    protected function buildStorage(): TracksMigrations
    {
        $active = array_filter([
            'fileStorage'   => $this->storagePath !== null,
            'sqliteStorage' => $this->sqlitePath !== null,
            'pdoStorage'    => $this->pdo !== null,
            'mysqliStorage' => $this->mysqli !== null,
        ]);

        if (count($active) > 1) {
            throw new LogicException(
                'Only one storage backend may be configured at a time. ' .
                'The following are set simultaneously: ' . implode(', ', array_keys($active)) . '().',
            );
        }

        if ($this->mysqli !== null) {
            return new MysqliStorage($this->mysqli, $this->mysqliTable);
        }

        if ($this->pdo !== null) {
            return new PdoStorage($this->pdo, $this->pdoTable);
        }

        if ($this->sqlitePath !== null) {
            $path = $this->sqlitePath === false
                ? $this->directory . '/.migrun/migrun.sqlite'
                : $this->sqlitePath;
            return new SqliteStorage($path, $this->sqliteTable);
        }

        // JSON file — default backend.
        return new JsonFileStorage($this->resolveStoragePath());
    }

    protected function resolveStoragePath(): string
    {
        if ($this->storagePath === null) {
            return $this->directory . '/.migrun/migrun.json';
        }

        if (
            is_dir($this->storagePath) ||
            pathinfo($this->storagePath, PATHINFO_EXTENSION) === '' ||
            pathinfo($this->storagePath, PATHINFO_FILENAME) === ''
        ) {
            return rtrim($this->storagePath, '/\\') . '/migrun.json';
        }

        return $this->storagePath;
    }
}
