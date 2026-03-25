<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

use Dakujem\Migrun\Executor\ContainerInvoker;
use Dakujem\Migrun\Executor\Executor;
use Dakujem\Migrun\Executor\TrivialInvoker;
use Dakujem\Migrun\Finder\DirectoryFinder;
use Dakujem\Migrun\Storage\JsonFileStorage;
use LogicException;
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
 *
 * The class is not final and may be extended to add project-specific defaults
 * or additional fluent setters.
 */
class MigrunBuilder
{
    protected ?string $directory = null;
    protected ?ContainerInterface $container = null;
    protected ?string $storage = null;
    protected bool $recursive = true;

    /**
     * Path to the directory that holds migration files.
     * Required — build() throws if this is not set.
     * Pass null to unset.
     */
    public function directory(?string $directory): static
    {
        $this->directory = $directory;
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
     * Path to the storage file or directory.
     *
     * - File path  → used as-is.
     * - Directory path → migrun.json is appended as the filename.
     * - Not set (null) → defaults to {migrations-dir}/.migrun/migrun.json.
     *
     * Pass null to revert to the default location.
     */
    public function storage(?string $path): static
    {
        $this->storage = $path;
        return $this;
    }

    /**
     * Whether the finder should scan subdirectories recursively.
     * Defaults to true.
     */
    public function recursive(bool $recursive = true): static
    {
        $this->recursive = $recursive;
        return $this;
    }

    /**
     * Build and return a fully wired Orchestrator.
     *
     * @throws LogicException if no migrations directory has been set.
     */
    public function build(): Orchestrator
    {
        if ($this->directory === null) {
            throw new LogicException('A migrations directory must be set via directory() before calling build().');
        }

        $storage = new JsonFileStorage($this->resolveStoragePath());
        $finder = new DirectoryFinder($this->directory, $this->recursive);
        $invoker = $this->container !== null
            ? new ContainerInvoker($this->container)
            : new TrivialInvoker();
        $executor = new Executor($invoker);

        return new Orchestrator($storage, $finder, $executor);
    }

    // -------------------------------------------------------------------------

    protected function resolveStoragePath(): string
    {
        if ($this->storage === null) {
            // Default: a .migrun sub-directory inside the migrations directory.
            return $this->directory . '/.migrun/migrun.json';
        }

        if (is_dir($this->storage)) {
            return rtrim($this->storage, '/\\') . '/migrun.json';
        }

        return $this->storage;
    }
}
