# Migrun

[![Test Suite](https://github.com/dakujem/migrun/actions/workflows/php-test.yml/badge.svg)](https://github.com/dakujem/migrun/actions/workflows/php-test.yml)
[![Coverage Status](https://coveralls.io/repos/github/dakujem/migrun/badge.svg?branch=trunk)](https://coveralls.io/github/dakujem/migrun?branch=trunk)

A lightweight, flexible migration runner for your PHP stack.

Database migrations on your terms.  
No framework lock-in. No config files. Any database.

>
> 💿 `composer require dakujem/migrun`
>
> 📒 [Changelog](changelog.md)
>


## What is Migrun?

Migrun looks for migration files and makes sure each one has run exactly once.
It is a lightweight, hackable tool to help you manage database changes consistently.
It is intended to be incorporated into your existing project setup.

Migrun does **not**:
- provide a migration framework
- provide a query builder
- provide a database abstraction layer or any sort of ORM
- enforce usage of a particular database connection type or library


## Migration file format

Migrun imposes **no restrictions on filenames**.  
By default, any `.php` file placed in the configured directory is picked up as a migration.

Each migration MUST _return_ a callable class or closure.
This is a distinction compared to other migration runners.


### Execution order

Migrations are ordered by their ID — the filename stem (the path relative to the migrations
directory, without the `.php` extension).
**The order in which migrations run is therefore determined entirely by the filename.**

IDs are compared **lexicographically** — byte by byte, exactly as `LC_ALL=C sort` or git would
compare them. It is deterministic and identical on every platform, filesystem and locale. This
one comparison decides the order pending migrations run in and the order `status` lists them.

> ⚠️ **Digits are compared as characters, not as numbers.**
> Byte-wise `'10'` comes **before** `'9'`, because `'1'` comes before `'9'`.

This matters only when a number in the filename varies in width. See
[Numbering your migrations](#numbering-your-migrations) below.

> **Rollback is the one exception.** Migrations are reverted in reverse order of *application*
> rather than of ID, so that "roll back the last one" undoes what was actually done last. The two
> coincide unless a migration was applied out of order — see
> [Rollback order follows application order](#rollback-order-follows-application-order).


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

Every timestamp is the same width, so lexicographic and chronological order coincide. This is
the recommended scheme precisely because it cannot run into the numbering pitfall below.

> The timestamp in the filename is purely for ordering.
> The history storage records the time the migration *ran*, not the time encoded in the filename.


### Numbering your migrations

If you prefer plain version numbers to timestamps, **zero-pad them to a fixed width**:

```
0001_create_users.php         ✅ correct
0002_add_email_index.php
0009_backfill_slugs.php
0010_add_orders.php
```

```
1_create_users.php            ❌ wrong order once you reach 10
2_add_email_index.php
9_backfill_slugs.php
10_add_orders.php             ← runs FIRST, before 1_create_users
```

Unpadded numbers sort as `1, 10, 2, 9` — so the tenth migration runs before the second.
Pad wide enough for the number of migrations you expect (three digits gets you to 999).
Using 6 digits is not an overkill but a precaution.

**A prefix does not help.** The comparison is still byte-wise, so `v1 … v10` breaks in exactly
the same way:

```
v1.php  v2.php  v9.php  v10.php     →  runs as  v1, v10, v2, v9     ❌
v001.php  v002.php  v009.php  v010.php  →  runs as  v001, v002, v009, v010   ✅
```

The same applies to `rel-1`, `step_1`, `2024-1`, and any other scheme with an unpadded number
anywhere in the name. **Pad the digits.**

> ⚠️ **Upgrading from 1.0 with plain numeric filenames** (`1.php`, `2.php`, … `10.php`)?
> The ID comparison was fixed in 1.0.1 and their order changed.
> See the [changelog](changelog.md#v101) for what to do.


### Migration format A — anonymous class (up + down)

The file **returns** an anonymous class with `up()` and `down()` methods. No interface required.
Typed parameters are autowired from the PSR-11 container by class name (when using `ContainerInvoker`).

```php
<?php
// migrations/20240115_093000_add_email_index.php

use PDO;

return new class
{
    public function up(PDO $db): void
    {
        $db->exec('CREATE INDEX idx_users_email ON users (email)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP INDEX idx_users_email');
    }
};
```


### Migration format B — anonymous class (up only)

The file **returns** an anonymous class with an `up()` method only. Rollback is not supported.

```php
<?php
// migrations/20240101_120000_create_users.php

use PDO;

return new class
{
    public function up(PDO $db): void
    {
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    }
};
```


### Migration format C — callable (up only)

The file **returns** a callable. Rollback is not supported.

```php
<?php
// migrations/20240101_120000_create_users.php

use PDO;

return function (PDO $db): void {
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
};
```

> **Edge case:** if a returned object has both a public `up()` method and is itself callable (i.e. defines `__invoke`), `up()` takes precedence and `__invoke` is never used.


### Migration format D — interface-based anonymous class

For projects that prefer explicit contracts, the file may return an instance implementing `Migration` or `ReversibleMigration`.
Behaviour is identical to returning a class defining either just the `up` method or both the `up` and `down` methods.

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

> Methods `up()` and `down()` may declare typed parameters beyond the parameter-less interface signature.
> PHP's LSP rules require these to have default values, but the invoker will override the defaults with container-resolved instances automatically.


## Quick setup

### Recommended — PDO storage with a container

The most practical setup: store the migration history in the **same database you are migrating**. This keeps everything in one place, avoids a separate file to manage or gitignore, and lets the history participate in database backups and restores naturally.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Dakujem\Migrun\MigrunBuilder;

$container = require __DIR__ . '/bootstrap/container.php'; // any PSR-11 container

// $pdo is resolved from the container — the same database being migrated
$pdo = $container->get(PDO::class);

$orchestrator = (new MigrunBuilder())
    ->directory(__DIR__ . '/migrations')
    ->container($container)   // enables autowiring of migration parameters
    ->pdoStorage($pdo)        // history stored in the same DB, table: migrun_migrations
    ->build();

$executed = $orchestrator->run();
foreach ($executed as $migration) {
    echo "Migrated: {$migration->id()}" . PHP_EOL;
}
```

`PdoStorage` creates the history table automatically on first use. Works with MySQL, PostgreSQL, SQLite, and any other PDO-compatible database.


### Minimal — no options

The absolute minimum: only the migrations directory is required. Everything else uses built-in defaults.

```php
$orchestrator = (new MigrunBuilder())
    ->directory(__DIR__ . '/migrations')
    ->build();
```

Storage defaults to `{migrations-dir}/.migrun/migrun.json` — no extra configuration needed. Migration files must accept no arguments (or have all defaults).

> **Important:** The default JSON storage file tracks which migrations have already run.
> If it is committed to version control and then overwritten (e.g. reset to an earlier state or deleted),
> Migrun will re-run migrations that have already been applied. Add the file to `.gitignore` to prevent this:
> ```
> # migrun storage
> {migrations-dir}/.migrun/*
> ```
> This covers both the default JSON file and the default SQLite file, since both live under `.migrun/`.
> If you configure a custom storage path, gitignore that path instead.
> Using PDO storage in the same database avoids this concern entirely.


### All builder options

```php
use Dakujem\Migrun\MigrunBuilder;

$orchestrator = (new MigrunBuilder())
    ->directory(__DIR__ . '/migrations')           // required; pass recursive: false to disable subdirectory scanning
    ->container($container)                        // PSR-11 container; omit for no-autowiring mode
    ->pdoStorage($pdo)                             // recommended: history in the same DB as migrations
    ->reporter(new MyReporter())                   // live progress as each migration runs; omit for none
    ->build();
```

**Storage backend** (mutually exclusive — `build()` throws if more than one is set):

```php
// Any PDO connection (MySQL, PostgreSQL, SQLite, …) — recommended
->pdoStorage($pdo)                                // default table name (migrun_migrations)
->pdoStorage($pdo, table: 'schema_history')       // custom table name

// mysqli connection (MySQL/MariaDB only)
->mysqliStorage($mysqli)                          // default table name (migrun_migrations)
->mysqliStorage($mysqli, table: 'schema_history') // custom table name

// SQLite database file
->sqliteStorage()                                 // {migrations-dir}/.migrun/migrun.sqlite
->sqliteStorage(__DIR__ . '/var/history.sqlite')  // explicit path
->sqliteStorage(table: 'schema_history')          // default path, custom table name

// JSON file — default when nothing is set
->fileStorage(__DIR__ . '/var/migrun')     // directory → appends /migrun.json
                                           // file path → used as-is
                                           // omit → {migrations-dir}/.migrun/migrun.json
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
    echo "Migrated: {$migration->id()}" . PHP_EOL;
}

// Roll back the last migration
// $reverted = $orchestrator->rollback(1);
```


## Running and rolling back

Every method returns `MigrationRun[]` — the migrations it actually executed, each carrying its
measured duration.

| Method | Does |
|---|---|
| `run()` | Applies every pending migration. |
| `runTo($id)` | Applies pending migrations up to **and including** `$id`; later ones stay pending. |
| `rollback($steps = 1)` | Reverts the `$steps` **most recently applied** migrations. |
| `rollbackBefore($id)` | Reverts `$id` **and everything applied after it**, leaving the state before `$id`. |
| `rollbackAll()` | Reverts everything applied. |
| `rollbackExactly($ids)` | Reverts exactly the named migrations, in the order given. |
| `status()` | Reports every known migration, ascending by ID. |

One rule ties the targeted methods together:

> **The migration you name is always acted upon.**
> `runTo($id)` applies it, `rollbackBefore($id)` reverts it, `rollbackExactly([$id])` reverts it.

So picking the newest applied migration in a UI and calling `rollbackBefore()` reverts exactly that
one — never a no-op.

### Rollback order follows application order

This matters, so it is worth being explicit: **rollback proceeds in reverse order of *application*,
not of ID.** "Roll back the last migration" means the one applied most recently, which is not
necessarily the one with the highest ID.

Consider a branch merged into a database that already had newer migrations applied:

```
0001_create_users     applied 2026-07-01 09:00
0002_add_widget       applied 2026-07-28 14:22   ← applied LAST, despite the lower ID
0003_add_orders       applied 2026-07-05 11:30
```

`rollback(1)` reverts `0002_add_widget` — the migration you actually applied last — rather than
`0003_add_orders`, which belongs to someone else. Reverse-application order also unwinds the schema
in the exact reverse of how it was built, so each `down()` runs against the state its `up()` produced.

**In the normal case the two orders are the same thing** — they diverge only when a migration was
applied out of order, as above. `status()` lists ascending by ID while showing each `applied_at`, so
that situation shows up as a non-monotonic timestamp column: the quickest way to spot it before
rolling anything back.

`rollbackBefore($id)` chooses its *set* by ID — the same byte-wise comparison `status()` lists by, so
it is exactly "this entry and everything below it" — but reverts that set in application order. Use
`rollbackExactly()` when you need to control the sequence yourself.

### Reverting specific migrations

`rollbackExactly()` is the escape hatch for the branch workflow: revert your own migration while
newer ones from elsewhere stay applied.

```php
// Undo just this one, out of the middle of the history.
$runner->rollbackExactly(['20260701_120000_add_widget']);
```

It imposes no ordering of its own — migrations are reverted in the order the array lists them. A
status listing is ascending by ID, which is the **opposite** of a sensible rollback order, so reverse
it first:

```php
$displayed = ['0002_add_widget', '0003_add_orders'];   // as shown, top to bottom
$runner->rollbackExactly($displayed);                  // ❌ reverts 0002 before 0003
$runner->rollbackExactly(array_reverse($displayed));   // ✅ newest first
```

Reverting out of order **deliberately leaves a gap**: the migration becomes pending again, and a
later `run()` re-applies it at its ID position — therefore *after* migrations with higher IDs. Migrun
supports this (it has always tolerated gaps, since each migration is tracked individually rather than
by a high-water mark), but whether it is safe for your schema is your call. A migration that ran
against a newer schema may not revert cleanly against an older one.

`rollbackBefore()` and `rollbackExactly()` throw `MigrationNotAppliedException` if a named migration
is not currently applied, and `runTo()` throws `MigrationNotFoundException` for an unknown target —
a mistyped or truncated ID must never silently act on a different set.


## Running from the CLI

Migrun ships no CLI command of its own — keeping it decoupled from any console framework. The recommended pattern is a small standalone PHP script you invoke directly.


### Standalone script

Create `bin/migrate.php` (or wherever suits your project):

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Dakujem\Migrun\MigrationState;
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
            echo "Migrated: {$m->id()}" . PHP_EOL;
        }
    })(),

    'rollback' => (function () use ($orchestrator, $argv) {
        $steps = (int) ($argv[2] ?? 1);
        $reverted = $orchestrator->rollback($steps);
        foreach ($reverted as $m) {
            echo "Reverted: {$m->id()}" . PHP_EOL;
        }
    })(),

    'status' => (function () use ($orchestrator) {
        $entries = $orchestrator->status();

        if (empty($entries)) {
            echo "No migrations found." . PHP_EOL;
            return;
        }

        $idWidth = max(array_map(fn($e) => strlen($e->id), $entries));
        $idWidth = max($idWidth, 2); // minimum column width

        $header = sprintf(
            "%-{$idWidth}s  %-7s  %s",
            'Migration ID',
            'Status',
            'Applied at (UTC)' . '   ',
        );
        echo $header . PHP_EOL;
        echo str_repeat('-', strlen($header)) . PHP_EOL;

        $up = $down = 0;
        $missingSince = null;
        foreach ($entries as $entry) {
            echo sprintf(
                "%-{$idWidth}s  %-7s  %s",
                $entry->id,
                match ($entry->state) {
                    MigrationState::Applied => 'up',
                    MigrationState::Pending => 'down',
                    MigrationState::Missing => 'MISSING',
                },
                $entry->appliedAt?->format('Y-m-d H:i:s') ?? '-',
            ) . PHP_EOL;
            $up += MigrationState::Applied === $entry->state ? 1 : 0;
            $down += MigrationState::Pending === $entry->state ? 1 : 0;
            if (MigrationState::Missing === $entry->state) {
                $missingSince = $entry->appliedAt;
            }
        }

        echo str_repeat('-', strlen($header)) . PHP_EOL;
        if ($missingSince) {
            echo "WARNING! Some migration files missing since {$missingSince->format('Y-m-d H:i:s')}." . PHP_EOL;
        }
        echo "Total: {$up} up, {$down} down" . PHP_EOL;
    })(),

    default => (function () use ($command) {
        echo "Unknown command: {$command}" . PHP_EOL;
        echo "Usage: migrate.php [run|rollback [steps]|status]" . PHP_EOL;
        exit(1);
    })(),
};
```

Make it executable and run:

```bash
php bin/migrate.php run
php bin/migrate.php rollback
php bin/migrate.php rollback 3
php bin/migrate.php status
```

> 📄 **A complete, ready-to-adapt version of this script lives in
> [`examples/migrate.php`](examples/migrate.php).**
> It builds on the snippet above with live progress output, concurrency locking, a
> `create` scaffolder, colored terminal output, and CI-friendly exit codes.
> Start there if you want the full picture of a finished Migrun integration —
> the sections below explain each piece of it in isolation.


### Via Composer scripts

Add the commands to the `scripts` section of your `composer.json`:

```json
{
    "scripts": {
        "migrate:up":     "@php bin/migrate.php run",
        "migrate:down":   "@php bin/migrate.php rollback",
        "migrate:status": "@php bin/migrate.php status"
    }
}
```

Then run:

```bash
composer migrate:up
composer migrate:down
composer migrate:status
```

Pass extra arguments with `--`:

```bash
composer migrate:down -- 3
```


### Symfony Console command

If your project already uses Symfony Console:

```php
<?php

use Dakujem\Migrun\MigrationState;
use Dakujem\Migrun\Orchestrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateCommand extends Command
{
    public function __construct(private Orchestrator $runner)
    {
        parent::__construct('db:migrate');
    }

    protected function configure(): void
    {
        $this->addArgument('command', InputArgument::OPTIONAL, 'run | rollback | status', 'run');
        $this->addArgument('steps', InputArgument::OPTIONAL, 'Number of migrations to roll back', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return match ($input->getArgument('command')) {
            'run' => $this->runMigrations($output),
            'rollback' => $this->rollback($output, (int) $input->getArgument('steps')),
            'status' => $this->status($output),
            default => (function () use ($input, $output) {
                $output->writeln("<error>Unknown command: {$input->getArgument('command')}</error>");
                return Command::FAILURE;
            })(),
        };
    }

    private function runMigrations(OutputInterface $output): int
    {
        $executed = $this->runner->run();
        if (empty($executed)) {
            $output->writeln('Nothing to run.');
        }
        foreach ($executed as $m) {
            $output->writeln("Migrated: {$m->id()}");
        }
        return Command::SUCCESS;
    }

    private function rollback(OutputInterface $output, int $steps): int
    {
        $reverted = $this->runner->rollback($steps);
        foreach ($reverted as $m) {
            $output->writeln("Reverted: {$m->id()}");
        }
        return Command::SUCCESS;
    }

    private function status(OutputInterface $output): int
    {
        $entries = array_filter(
            iterator_to_array($this->runner->status()),
            fn($e) => $e->state !== MigrationState::Missing,
        );

        if (empty($entries)) {
            $output->writeln('No migrations found.');
            return Command::SUCCESS;
        }

        $idWidth = max(array_map(fn($e) => strlen($e->id), $entries));
        $idWidth = max($idWidth, 2);

        $output->writeln(sprintf("%-{$idWidth}s  %-7s  %s", 'ID', 'Status', 'Applied at'));
        $output->writeln(str_repeat('-', $idWidth + 22));

        foreach ($entries as $entry) {
            $output->writeln(sprintf(
                "%-{$idWidth}s  %-7s  %s",
                $entry->id,
                match ($entry->state) {
                    MigrationState::Applied => 'up',
                    MigrationState::Pending => 'down',
                    MigrationState::Missing => 'MISSING',
                },
                $entry->appliedAt?->format('Y-m-d H:i:s') ?? '-',
            ));
        }

        return Command::SUCCESS;
    }
}
```

Wire it the same way as any other command in your framework:

```bash
php bin/console db:migrate
php bin/console db:migrate rollback
php bin/console db:migrate rollback 3
php bin/console db:migrate status
```


### Live progress output

The examples above collect the result array and print it *after* the whole batch
finishes. For a long-running set — or just nicer feedback — you can report
progress **as each migration runs** by giving the runner a reporter.

A reporter implements `ReportsMigrations`. The `Orchestrator` calls it around
every individual migration:

- `starting()` — a migration is about to run (Up or Down),
- `finished()` — it succeeded (with its measured duration),
- `failed()` — it threw; the run then aborts as usual, re-throwing the error.

Because `failed()` receives the exact migration that threw, you no longer need to
reconstruct *where* a run stopped — the reporter names it directly.

#### A plain reporter (standalone script)

Aligned with the `bin/migrate.php` script above — it just writes to stdout:

```php
<?php

use Dakujem\Migrun\Direction;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationRun;
use Dakujem\Migrun\ReportsMigrations;

final class EchoReporter implements ReportsMigrations
{
    public function starting(MigrationFile $file, Direction $direction): void
    {
        $verb = $direction === Direction::Up ? 'Migrating' : 'Reverting';
        echo "{$verb} {$file->id()} ... ";
    }

    public function finished(MigrationRun $run, Direction $direction): void
    {
        echo sprintf('done (%.3fs)', $run->durationSeconds) . PHP_EOL;
    }

    public function failed(MigrationFile $file, Direction $direction, \Throwable $error): void
    {
        echo 'FAILED' . PHP_EOL;
        echo "  {$error->getMessage()}" . PHP_EOL;
    }
}
```

Wire it via the builder — everything else in the script stays the same:

```php
$orchestrator = (new MigrunBuilder())
    ->directory(__DIR__ . '/../migrations')
    ->container($container)
    ->reporter(new EchoReporter())   // live progress as each migration runs
    ->build();

// run()/rollback() now print as they go; the returned array is still
// available if you want a final summary on top.
$orchestrator->run();
```

Output while running:

```
Migrating 20240101_120000_create_users ... done (0.012s)
Migrating 20240115_093000_add_email_index ... done (0.004s)
Migrating 20240120_080000_backfill_slugs ... FAILED
  SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry
```

> Only need to react to *some* events? Extend `NullReporter` (a no-op base) and
> override just the methods you care about, instead of implementing the full
> interface. This also keeps your reporter working if the contract ever grows.

#### A Symfony Console reporter

Aligned with the `MigrateCommand` above, this one writes through the command's
`OutputInterface` and uses console styling tags:

```php
<?php

use Dakujem\Migrun\Direction;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationRun;
use Dakujem\Migrun\ReportsMigrations;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ConsoleReporter implements ReportsMigrations
{
    public function __construct(private OutputInterface $output) {}

    public function starting(MigrationFile $file, Direction $direction): void
    {
        $verb = $direction === Direction::Up ? 'Migrating' : 'Reverting';
        $this->output->write("{$verb} <info>{$file->id()}</info> ... ");
    }

    public function finished(MigrationRun $run, Direction $direction): void
    {
        $this->output->writeln(sprintf('<comment>done (%.3fs)</comment>', $run->durationSeconds));
    }

    public function failed(MigrationFile $file, Direction $direction, \Throwable $error): void
    {
        $this->output->writeln('<error>FAILED</error>');
        $this->output->writeln("  {$error->getMessage()}");
    }
}
```

The reporter needs the `$output`, which only exists inside `execute()`. Rather than
rebuild the runner per invocation, pass the reporter **per call**: `run()` and
`rollback()` accept an optional trailing `ReportsMigrations` argument that overrides
the constructor's reporter for that one call. So inject a normal, reusable
`Orchestrator` and hand it a fresh reporter each run:

```php
final class MigrateCommand extends Command
{
    // A plain, reusable service — built once, injected like any other.
    public function __construct(private Orchestrator $runner)
    {
        parent::__construct('db:migrate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Request-scoped: the reporter wraps this invocation's $output.
        $reporter = new ConsoleReporter($output);

        match ($input->getArgument('command')) {
            'run'      => $this->runner->run($reporter),
            'rollback' => $this->runner->rollback((int) $input->getArgument('steps'), $reporter),
            'status'   => $this->status($output),   // unchanged — status() takes no reporter
            default    => throw new \InvalidArgumentException('Unknown command.'),
        };

        return Command::SUCCESS;
    }
}
```

See [`examples/migrate.php`](examples/migrate.php) for a reporter that also handles
colored output, with TTY and [`NO_COLOR`](https://no-color.org) detection.

#### Passing a reporter through an abstraction

The base `RunsMigrations` contract deliberately declares no reporter parameter, so it
cannot express "pass a reporter". When you need that through an interface rather than
the concrete class — typically in a **decorator** — type against
`RunsMigrationsWithReporter`, which extends `RunsMigrations` and adds the parameter:

```php
use Dakujem\Migrun\RunsMigrationsWithReporter;

// Orchestrator implements this, so it is also a RunsMigrations.
function migrate(RunsMigrationsWithReporter $runner, ReportsMigrations $reporter): void
{
    $runner->run($reporter);
}
```

> **Why this matters in a decorator.** If you type the wrapped runner as plain
> `RunsMigrations` and still call `$inner->run($reporter)`, PHP silently discards the
> argument for any implementation that does not declare it — the reporter vanishes and
> live progress goes quiet, with no error at all. Static analysers do flag the call.
> Use `RunsMigrationsWithReporter` for both the decorator and its inner runner.


## Extending

### Concepts

| Role | Interface | Built-in |
|---|---|---|
| Track applied migrations | `TracksMigrations` | `JsonFileStorage` — JSON file on disk<br>`PdoStorage` — any PDO database<br>`SqliteStorage` — SQLite file (wraps `PdoStorage`)<br>`MysqliStorage` — MySQL/MariaDB via mysqli |
| Discover migration files | `DiscoversMigrations` | `DirectoryFinder` — scans a directory |
| Invoke migration callables | `InvokesCallable` | `ContainerInvoker` (PSR-11 autowired), `TrivialInvoker` (no args) |
| Load and run a migration | `ExecutesMigrations` | `Executor` — delegates to an `InvokesCallable` |
| Report progress during a run | `ReportsMigrations` | `NullReporter` — no-op default and base class |
| Orchestrate the whole flow | `RunsMigrations`<br>`RunsMigrationsWithReporter` — adds the per-call reporter | `Orchestrator` |

Every part is replaceable. Wire the built-ins for quick setup; swap them out as your project grows.


### Custom storage

For the common case of a SQL database, use the built-in `PdoStorage` (or `SqliteStorage`):

```php
use Dakujem\Migrun\Storage\PdoStorage;
use Dakujem\Migrun\Storage\SqliteStorage;

// Any PDO connection — table is created automatically
$storage = new PdoStorage($pdo);
$storage = new PdoStorage($pdo, 'schema_history'); // custom table name

// SQLite convenience wrapper
$storage = new SqliteStorage(__DIR__ . '/var/migrun.sqlite');
```

Wire it via the builder:

```php
(new MigrunBuilder())
    ->directory(__DIR__ . '/migrations')
    ->pdoStorage($pdo)                        // or ->sqliteStorage()
    ->build();
```

For anything else — Redis, S3, a remote API — implement `TracksMigrations` directly:

```php
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationHistoryEntry;
use Dakujem\Migrun\TracksMigrations;

final class RedisStorage implements TracksMigrations
{
    public function __construct(private \Redis $redis, private string $key = 'migrations') {}

    public function getApplied(): iterable
    {
        $entries = [];
        foreach ($this->redis->hGetAll($this->key) as $id => $at) {
            $entries[] = new MigrationHistoryEntry($id, new \DateTimeImmutable($at));
        }
        usort($entries, fn($a, $b) => $b->at() <=> $a->at());
        return $entries;
    }

    public function isApplied(MigrationFile $migration): bool
    {
        return (bool) $this->redis->hExists($this->key, $migration->id());
    }

    public function markApplied(MigrationFile $migration, ?\DateTimeImmutable $at = null): void
    {
        $this->redis->hSet($this->key, $migration->id(), ($at ?? new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM));
    }

    public function markReverted(MigrationFile $migration, ?\DateTimeImmutable $at = null): void
    {
        $this->redis->hDel($this->key, $migration->id());
    }
}
```


### Custom finder

Implement `DiscoversMigrations` to customize the way migrations are discovered (filtering, multiple directories, etc.).

**Ordering is not your concern.** `Orchestrator` sorts whatever `list()` returns, comparing IDs
byte by byte, so a finder cannot put run order out of step with rollback or `status` order. Return
files in any order you like. `DirectoryFinder` still sorts its own output, for the benefit of code
that uses the finder directly.

The same holds for storage: the orchestrator orders the migration history itself rather than
trusting a database collation or a file's insertion order. There is exactly one ordering rule in
the library, applied in one place.


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

Implement `ExecutesMigrations` to wrap each migration in a transaction, add logging, emit events, etc.:

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


### Concurrency and locking

Migrun ships **no locking**.  
If two migration runs can overlap — two deploys racing, a
CI job and a manual run, two web nodes booting at once — you should serialize them
yourself. This section shows how, in a few lines of your own code.

#### Lock the whole runner, not each migration

`Orchestrator::run()` loops over migrations doing *check-then-act*: it asks storage
`isApplied()`, executes the migration, then records it. Across two processes that is a
[TOCTOU](https://en.wikipedia.org/wiki/Time-of-check_to_time-of-use) race — both can
see the same migration as pending and both run it. Locking each migration
individually does **not** fix this (the gap between check and lock still races, and
interleaving two runs violates the ordering that later migrations depend on).

A single lock around the **entire run** makes the whole check-execute-record sequence
atomic and preserves order. It is both the correct granularity and the simpler one.

#### The `RunsMigrations` seam

`Orchestrator` implements the `RunsMigrations` interface (`run()`, `rollback()`,
`status()`). Type against it and you can wrap the runner transparently — for locking,
but equally for logging, timing, or event emission:

```php
use Dakujem\Migrun\RunsMigrations;
```

> If your decorator needs to forward a **reporter** for live progress, type it and its
> inner runner against `RunsMigrationsWithReporter` instead — see
> [Passing a reporter through an abstraction](#passing-a-reporter-through-an-abstraction).
> The decorator below keeps the plain contract, since it forwards no reporter.

#### A minimal mutex contract

Define a tiny lock abstraction. A `withLock(callable)` shape (rather than
separate acquire/release calls) guarantees the lock is released even if a migration
throws — mirroring how `InvokesCallable::invoke()` works in this library:

```php
interface Mutex
{
    /** Run $critical while holding an exclusive lock; release on return or throw. */
    public function withLock(callable $critical): mixed;
}

final class CouldNotAcquireLock extends \RuntimeException {}
```

#### A locking decorator

Wrap the runner. Only `run()` and `rollback()` mutate — `status()` is read-only and
passes through unlocked:

```php
use Dakujem\Migrun\RunsMigrations;

final readonly class LockingOrchestrator implements RunsMigrations
{
    public function __construct(
        private RunsMigrations $inner,
        private Mutex $mutex,
    ) {}

    public function run(): array
    {
        return $this->mutex->withLock(fn() => $this->inner->run());
    }

    public function rollback(int $steps = 1): array
    {
        return $this->mutex->withLock(fn() => $this->inner->rollback($steps));
    }

    public function status(): array
    {
        return $this->inner->status();
    }
}
```

#### Example: file lock (`flock`)

Good for single-host setups and the JSON/SQLite storage backends. The OS releases the
lock automatically if the process dies, so a crash cannot strand it:

```php
final class FlockMutex implements Mutex
{
    /** @param bool $wait true = block until the lock is free; false = fail fast. */
    public function __construct(
        private string $lockFile,
        private bool $wait = true,
    ) {}

    public function withLock(callable $critical): mixed
    {
        $handle = fopen($this->lockFile, 'c');
        if ($handle === false) {
            throw new CouldNotAcquireLock("Cannot open lock file: {$this->lockFile}");
        }
        $flags = LOCK_EX | ($this->wait ? 0 : LOCK_NB);
        if (!flock($handle, $flags)) {
            fclose($handle);
            throw new CouldNotAcquireLock("Another migration run holds the lock: {$this->lockFile}");
        }
        try {
            return $critical();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
```

#### Example: database advisory lock (PDO)

For multi-host setups, a database advisory lock coordinates every node through the
database itself. Advisory locks are **session-scoped**, so the mutex must reuse the
**same PDO connection** that runs the migrations (and, ideally, the storage). The lock
is released automatically if the connection drops:

```php
// MySQL / MariaDB — GET_LOCK / RELEASE_LOCK
final class PdoAdvisoryMutex implements Mutex
{
    public function __construct(
        private \PDO $pdo,            // the SAME connection used to run migrations
        private string $name = 'migrun',
        private int $timeout = 10,    // seconds to wait before giving up
    ) {}

    public function withLock(callable $critical): mixed
    {
        $acquire = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $acquire->execute([$this->name, $this->timeout]);
        if ((string) $acquire->fetchColumn() !== '1') {
            throw new CouldNotAcquireLock("Timed out acquiring advisory lock: {$this->name}");
        }
        try {
            return $critical();
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->name]);
        }
    }
}
```

For **PostgreSQL**, swap the SQL for session advisory locks:
`SELECT pg_advisory_lock(hashtext(?))` to acquire and
`SELECT pg_advisory_unlock(hashtext(?))` to release (Postgres keys are integers, so
hash the name). **SQLite** has no advisory-lock function — use `FlockMutex` on the
database file instead.

#### Trade-offs

- **Wait vs. fail fast.** CLI/deploy runs usually want to *wait* (block with a
  timeout) so a racing run queues instead of erroring; a CI gate may prefer to fail
  fast. Make it a constructor flag on the concrete mutex, as shown above.
- **Crash safety.** `flock` and database advisory locks auto-release when the process
  or connection dies. Avoid a "lock row" design (INSERT a sentinel row, delete it at
  the end) — if the process is killed mid-run the row is stranded and needs manual or
  TTL-based cleanup.
- **Same connection for advisory locks.** Because `GET_LOCK` / `pg_advisory_lock` are
  bound to the session, `PdoAdvisoryMutex` must share the connection that executes the
  migrations. The recommended single-`$pdo` setup already satisfies this.

#### Wiring it up

```php
$orchestrator = (new MigrunBuilder())
    ->directory(__DIR__ . '/migrations')
    ->pdoStorage($pdo)
    ->container($container)
    ->build();

// Wrap with the lock of your choice:
$runner = new LockingOrchestrator($orchestrator, new PdoAdvisoryMutex($pdo));
// or, for single-host / file storage:
// $runner = new LockingOrchestrator($orchestrator, new FlockMutex(__DIR__ . '/migrations/.migrun/migrun.lock'));

$runner->run();
```

`$runner` is a `RunsMigrations`, so it drops straight into the CLI script or Symfony
command shown earlier in place of the bare `Orchestrator`.

[`examples/migrate.php`](examples/migrate.php) shows this wired into a working script —
the mutex, the decorator, and a distinct exit code so a caller can tell lock contention
apart from a failed migration.


### Custom rollback order

Rollbacks proceed in reverse **application** order — see
[Rollback order follows application order](#rollback-order-follows-application-order). There is no
setting to change that, and none is needed: `rollbackExactly()` reverts migrations in whatever order
you hand it, so any ordering policy can be expressed as a decorator over the runner.

This one reverts in reverse **ID** order instead — the order a `status` listing shows, and what Phinx
calls `version_order: creation`:

```php
use Dakujem\Migrun\Exception\MigrationNotAppliedException;
use Dakujem\Migrun\MigrationState;
use Dakujem\Migrun\MigrationStatusEntry;
use Dakujem\Migrun\ReportsMigrations;
use Dakujem\Migrun\RunsMigrationsWithReporter;

final readonly class IdOrderRollback implements RunsMigrationsWithReporter
{
    public function __construct(private RunsMigrationsWithReporter $inner) {}

    public function rollback(int $steps = 1, ?ReportsMigrations $reporter = null): array
    {
        return $this->inner->rollbackExactly(
            array_slice($this->appliedByIdDesc(), 0, max($steps, 0)),
            $reporter,
        );
    }

    public function rollbackBefore(string $id, ?ReportsMigrations $reporter = null): array
    {
        $applied = $this->appliedByIdDesc();
        if (!in_array($id, $applied, true)) {
            throw new MigrationNotAppliedException($id); // keep the contract of the wrapped runner
        }

        return $this->inner->rollbackExactly(
            array_values(array_filter($applied, fn(string $a) => strcmp($a, $id) >= 0)),
            $reporter,
        );
    }

    public function rollbackAll(?ReportsMigrations $reporter = null): array
    {
        return $this->inner->rollbackExactly($this->appliedByIdDesc(), $reporter);
    }

    /** @return string[] Applied migration IDs, highest first. */
    private function appliedByIdDesc(): array
    {
        $ids = array_values(array_map(
            fn(MigrationStatusEntry $e) => $e->id,
            array_filter(
                $this->inner->status(),
                fn(MigrationStatusEntry $e) => $e->state === MigrationState::Applied,
            ),
        ));
        usort($ids, fn(string $a, string $b) => strcmp($b, $a));

        return $ids;
    }

    // Everything else passes through unchanged.
    public function run(?ReportsMigrations $r = null): array { return $this->inner->run($r); }
    public function runTo(string $id, ?ReportsMigrations $r = null): array { return $this->inner->runTo($id, $r); }
    public function rollbackExactly(array $orderedIds, ?ReportsMigrations $r = null): array { return $this->inner->rollbackExactly($orderedIds, $r); }
    public function status(): array { return $this->inner->status(); }
}
```

Three things to know before reaching for it:

- **`rollback($steps)` changes meaning.** "The last one" becomes the highest-ID applied migration
  rather than the one applied most recently. After a branch merge that is somebody else's migration.
- **It can fail where application order would not.** Reverting a higher ID first leaves a
  later-applied, lower-ID migration in place — and that migration's `up()` ran *with* the higher one
  present, so it may depend on it.
- **Put the lock outermost.** `new LockingOrchestrator(new IdOrderRollback($orchestrator), $mutex)`,
  not the other way round. The decorator reads `status()` before reverting, and only this nesting
  keeps that read and the reversal inside one lock.

A decorator must implement every method of the interface. That is not a trap — omitting one is a
fatal error when the class loads, never a silent pass-through.


### Seeders

Because an `Orchestrator` is just a directory + storage + invoker, you can run a second one for database seeders with no extra infrastructure:

```php
$migrations = (new MigrunBuilder())
    ->directory(__DIR__ . '/migrations')
    ->pdoStorage($pdo)                            // table: migrun_migrations
    ->container($container)
    ->build();

$seeders = (new MigrunBuilder())
    ->directory(__DIR__ . '/seeds')
    ->pdoStorage($pdo, table: 'migrun_seeds')     // separate table, same database
    ->container($container)
    ->build();

$migrations->run();
$seeders->run();
```

Seeds are tracked independently of migrations — running one never affects the other's history.


## Migrating between storage backends

If you need to switch storage backends (e.g. from the default `JsonFileStorage` to `PdoStorage`),
use the storage API to transfer the history.
Read all applied migrations from the old backend in reverse order (oldest first), then mark them applied in the new one:

```php
use Dakujem\Migrun\Storage\JsonFileStorage;
use Dakujem\Migrun\Storage\PdoStorage;

$old = new JsonFileStorage(__DIR__ . '/migrations/.migrun/migrun.json');
$new = new PdoStorage($pdo);

$applied = $old->getApplied(); //   newest first
$migrationOrder = array_reverse( // oldest first
    is_array($applied) ? $applied : iterator_to_array($applied),
);
foreach ($migrationOrder as $entry) {
    $new->markApplied($entry->id(), $entry->at());
}
```

This works for any combination of backends.

> Switching between `PdoStorage` and `MysqliStorage` requires no data migration — both adapters use the same table schema (`id`, `applied_at`).
> Point the new adapter at the existing table and it works immediately.

