# Migrun

A lightweight, flexible migration runner for any stack.

Database migrations on your terms.  
No framework lock-in. No config files. Any database.

>
> 💿 `composer require dakujem/migrun`
>


## Migration file format

Migrun imposes **no restrictions on filenames**. Any `.php` file placed in the configured directory is picked up as a migration.


### Execution order

The built-in `DirectoryFinder` sorts migrations lexicographically by their ID, which is the filename stem (the path relative to the migrations directory, without the `.php` extension). **The order in which migrations run is therefore determined entirely by the filename.**


### Recommended naming convention

For execution order to match creation order, prefix each filename with a timestamp:

```
YYYYMMDD_HHMMSS_<name>.php
```

Examples:

```
20240101_120000_create_users.php
20240115_093000_add_email_index.php
```

With this convention, lexicographic and chronological order coincide. Any other stable, monotonically increasing prefix (a sequential number, a date-only stamp, etc.) works just as well — pick whatever your team finds clearest.

> The timestamp in the filename is purely for ordering. The history storage records the time the migration *ran*, not the time encoded in the filename.


### Format A — callable (up only)

The file returns a callable. Its typed parameters are autowired from the PSR-11 container by class name (when using `ContainerInvoker`).

```php
<?php
// migrations/20240101_120000_create_users.php

use PDO;

return function (PDO $db): void {
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
};
```


### Format B — anonymous class (up + down)

The file returns a `ReversibleMigration` instance to support rollback.

```php
<?php
// migrations/20240115_093000_add_email_index.php

use Dakujem\Migrun\ReversibleMigration;
use PDO;

return new class implements ReversibleMigration
{
    public function up(?PDO $db = null): void
    {
        $db->exec('CREATE INDEX idx_users_email ON users (email)');
    }

    public function down(?PDO $db = null): void
    {
        $db->exec('DROP INDEX idx_users_email');
    }
};
```

> `up()` and `down()` may declare typed parameters beyond the parameter-less interface signature. PHP's LSP rules require these to have default values, and the invoker will override the defaults with container-resolved instances automatically.


## Quick setup

### Minimal — no container, no autowiring

The simplest possible setup. Migration files must accept no arguments (or have all defaults).

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Dakujem\Migrun\MigrunBuilder;

$orchestrator = (new MigrunBuilder())
    ->directory(__DIR__ . '/migrations')
    ->build();

$executed = $orchestrator->run();
foreach ($executed as $migration) {
    echo "Ran: {$migration->id()}" . PHP_EOL;
}
```

Storage defaults to `{migrations-dir}/.migrun/migrun.json` — no extra configuration needed.

> **Important:** The storage file tracks which migrations have already run. If it is committed to version control and then overwritten (e.g. reset to an earlier state or deleted), Migrun will re-run migrations that have already been applied. Add the file to `.gitignore` to prevent this:
> ```
> # migrun storage
> {migrations-dir}/.migrun/*
> ```
> If you configure a custom storage path, gitignore that path instead.


### With a PSR-11 container (autowiring)

Migration parameters are resolved from the container by type name.

```php
$container = require __DIR__ . '/bootstrap/container.php'; // any PSR-11 container

$orchestrator = (new MigrunBuilder())
    ->directory(__DIR__ . '/migrations')
    ->container($container)
    ->build();
```


### All builder options

```php
use Dakujem\Migrun\MigrunBuilder;

$orchestrator = (new MigrunBuilder())
    ->directory(__DIR__ . '/migrations')   // required
    ->container($container)                // PSR-11 container; omit for no-autowiring mode
    ->storage(__DIR__ . '/var/migrun')     // directory → var/migrun/migrun.json;
                                           // file path → used as-is;
                                           // omit → {migrations-dir}/.migrun/migrun.json
    ->recursive(false)                     // scan subdirectories (default: true)
    ->build();
```


## Full manual composition

The builder is a convenience layer. Every collaborator can be constructed and wired by hand for complete control.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Dakujem\Migrun\Executor\ContainerInvoker;
use Dakujem\Migrun\Executor\Executor;
use Dakujem\Migrun\Executor\TrivialInvoker;
use Dakujem\Migrun\Finder\DirectoryFinder;
use Dakujem\Migrun\Orchestrator;
use Dakujem\Migrun\Storage\JsonFileStorage;

$container = require __DIR__ . '/bootstrap/container.php';

$orchestrator = new Orchestrator(
    storage:  new JsonFileStorage(__DIR__ . '/storage/migrations.json'),
    finder:   new DirectoryFinder(__DIR__ . '/migrations', recursive: false),
    executor: new Executor(new ContainerInvoker($container)),
);

// No container / no autowiring:
// executor: new Executor(new TrivialInvoker()),

$executed = $orchestrator->run();
foreach ($executed as $migration) {
    echo "Ran: {$migration->id()}" . PHP_EOL;
}

// Roll back the last migration
// $reverted = $orchestrator->rollback(1);
```


## Running from the CLI

Migrun ships no CLI command of its own — keeping it decoupled from any console framework. The recommended pattern is a small standalone PHP script you invoke directly.


### Standalone script

Create `bin/migrate.php` (or wherever suits your project):

```php
#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Dakujem\Migrun\MigrunBuilder;

$container = require __DIR__ . '/../bootstrap/container.php';

$orchestrator = (new MigrunBuilder())
    ->directory(__DIR__ . '/../migrations')
    ->container($container)
    ->build();

$command = $argv[1] ?? 'run';

match ($command) {
    'run' => (function () use ($orchestrator) {
        $executed = $orchestrator->run();
        if (empty($executed)) {
            echo "Nothing to run." . PHP_EOL;
            return;
        }
        foreach ($executed as $m) {
            echo "Ran:      {$m->id()}" . PHP_EOL;
        }
    })(),

    'rollback' => (function () use ($orchestrator, $argv) {
        $steps = (int) ($argv[2] ?? 1);
        $reverted = $orchestrator->rollback($steps);
        foreach ($reverted as $m) {
            echo "Reverted: {$m->id()}" . PHP_EOL;
        }
    })(),

    default => (function () use ($command) {
        echo "Unknown command: {$command}" . PHP_EOL;
        echo "Usage: migrate.php [run|rollback [steps]]" . PHP_EOL;
        exit(1);
    })(),
};
```

Make it executable and run:

```bash
php bin/migrate.php run
php bin/migrate.php rollback
php bin/migrate.php rollback 3
```


### Via Composer scripts

Add the commands to the `scripts` section of your `composer.json`:

```json
{
    "scripts": {
        "migrate:up":   "@php bin/migrate.php run",
        "migrate:down": "@php bin/migrate.php rollback"
    }
}
```

Then run:

```bash
composer migrate:up
composer migrate:down
```

Pass extra arguments with `--`:

```bash
composer migrate:down -- 3
```


### Symfony Console command

If your project already uses Symfony Console:

```php
<?php

use Dakujem\Migrun\Orchestrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateCommand extends Command
{
    public function __construct(private Orchestrator $runner)
    {
        parent::__construct('db:migrate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $executed = $this->runner->run();
        foreach ($executed as $m) {
            $output->writeln("Ran: {$m->id()}");
        }
        return Command::SUCCESS;
    }
}
```

Wire it the same way as any other command in your framework.


## Extending

### Concepts

| Role | Interface | Built-in |
|---|---|---|
| Track applied migrations | `TracksMigrations` | `JsonFileStorage` — JSON file on disk |
| Discover migration files | `DiscoversMigrations` | `DirectoryFinder` — scans a directory |
| Invoke migration callables | `InvokesCallable` | `ContainerInvoker` (PSR-11 autowired), `TrivialInvoker` (no args) |
| Load and run a migration | `ExecutesMigrations` | `Executor` — delegates to an `InvokesCallable` |
| Orchestrate the whole flow | — | `Orchestrator` |

Every part is replaceable. Wire the built-ins for quick setup; swap them out as your project grows.


### Custom storage

Implement `TracksMigrations` to track migrations in a database, Redis, S3, or anything else:

```php
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\TracksMigrations;

final class PdoStorage implements TracksMigrations
{
    public function __construct(private \PDO $db) {}

    public function getApplied(): iterable
    {
        $rows = $this->db
            ->query('SELECT id, ran_at FROM migrations ORDER BY ran_at DESC')
            ->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(
            fn($row) => new MigrationHistoryEntry(
                id: $row['id'],
                ranAt: new \DateTimeImmutable($row['ran_at']),
            ),
            $rows,
        );
    }

    public function isApplied(MigrationFile $migration): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM migrations WHERE id = ?');
        $stmt->execute([$migration->id()]);
        return (bool) $stmt->fetchColumn();
    }

    public function markApplied(MigrationFile $migration, ?\DateTimeImmutable $at = null): void
    {
        $stmt = $this->db->prepare('INSERT INTO migrations (id, ran_at) VALUES (?, ?)');
        $stmt->execute([$migration->id(), ($at ?? new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }

    public function markReverted(MigrationFile $migration, ?\DateTimeImmutable $at = null): void
    {
        $stmt = $this->db->prepare('DELETE FROM migrations WHERE id = ?');
        $stmt->execute([$migration->id()]);
    }
}
```


### Custom finder

Implement `DiscoversMigrations` to customize the way migrations are discovered (filtering, multiple directories, etc.).


### Custom invoker

Implement `InvokesCallable` to integrate any DI framework's invoker. Below are ready-to-copy adapters for two common packages.

**[php-di/invoker](https://github.com/PHP-DI/Invoker)**

```php
use Dakujem\Migrun\Executor\InvokesCallable;
use Invoker\InvokerInterface;

final readonly class PhpDiInvoker implements InvokesCallable
{
    public function __construct(private InvokerInterface $invoker) {}

    public function invoke(callable $fn): mixed
    {
        return $this->invoker->call($fn);
    }
}
```

Usage:

```php
use Invoker\Invoker;
use Invoker\ParameterResolver\Container\TypeHintContainerResolver;
use Invoker\ParameterResolver\DefaultValueResolver;
use Invoker\ParameterResolver\ResolverChain;

$invoker = new Invoker(
    new ResolverChain([
        new TypeHintContainerResolver($container),
        new DefaultValueResolver(),
    ]),
);

$orchestrator = new Orchestrator(
    storage:  new JsonFileStorage(__DIR__ . '/storage/migrations.json'),
    finder:   new DirectoryFinder(__DIR__ . '/migrations'),
    executor: new Executor(new PhpDiInvoker($invoker)),
);
```

**[dakujem/wire-genie](https://github.com/dakujem/wire-genie)**

```php
use Dakujem\Migrun\Executor\InvokesCallable;
use Dakujem\Wire\Invoker as GenieInvoker;

final readonly class WireGenieInvoker implements InvokesCallable
{
    public function __construct(private GenieInvoker $invoker) {}

    public function invoke(callable $fn): mixed
    {
        return $this->invoker->invoke($fn);
    }
}
```

Usage:

```php
use Dakujem\Wire\Genie;

$orchestrator = new Orchestrator(
    storage:  new JsonFileStorage(__DIR__ . '/storage/migrations.json'),
    finder:   new DirectoryFinder(__DIR__ . '/migrations'),
    executor: new Executor(new WireGenieInvoker(new Genie($container))),
);
```

### Custom executor

Implement `ExecutesMigrations` to wrap each migration in a transaction, add logging, emit events, etc:

```php
use Dakujem\Migrun\Direction;
use Dakujem\Migrun\ExecutesMigrations;
use Dakujem\Migrun\Executor\Executor;
use Dakujem\Migrun\MigrationFile;

final class TransactionalExecutor implements ExecutesMigrations
{
    public function __construct(
        private Executor $inner,
        private \PDO $db,
    ) {}

    public function execute(MigrationFile $migration, Direction $direction): void
    {
        $this->db->beginTransaction();
        try {
            $this->inner->execute($migration, $direction);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
```

