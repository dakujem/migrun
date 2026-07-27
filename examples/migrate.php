<?php

/**
 * Example CLI migration runner for dakujem/migrun.
 *
 * A single, self-contained script that ties together the pieces documented in the readme:
 *   - building an Orchestrator (PDO storage + PSR-11 container autowiring),
 *   - live progress via a reporter (ReportsMigrations),
 *   - serializing concurrent runs with a Mutex + LockingOrchestrator decorator,
 *   - a tiny `create` scaffolder.
 *
 * Commands:
 *   php migrate.php run                 execute all pending migrations
 *   php migrate.php rollback [steps]    roll back the last [steps] migrations (default 1)
 *   php migrate.php status              show the status of all migrations
 *   php migrate.php create <name>       scaffold a new migration file
 *
 * Options:
 *   --ansi / --no-ansi                  force or disable colored output (otherwise auto-detected)
 *
 * Exit codes:
 *   0   success — `status` also exits 0 when migrations are pending or missing,
 *       since it is a report rather than a gate
 *   1   usage error, filesystem error, or a failed migration
 *   2   could not acquire the migration lock (another run is in progress)
 */

declare(strict_types=1);

use Dakujem\Migrun\Direction;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\MigrationRun;
use Dakujem\Migrun\MigrationState;
use Dakujem\Migrun\MigrunBuilder;
use Dakujem\Migrun\NullReporter;
use Dakujem\Migrun\ReportsMigrations;
use Dakujem\Migrun\RunsMigrationsWithReporter;

// This script drives database migrations — it must never be reachable over HTTP.
if (PHP_SAPI !== 'cli') {
    throw new \RuntimeException('migrate.php is a CLI-only script and must not be run through a web server.');
}

/* ---------------------------------------------------------------------------
 * Project layout — adjust these to your setup. The paths assume this script sits
 * one level below the project root (e.g. bin/migrate.php).
 * ------------------------------------------------------------------------- */

const AUTOLOAD_FILE  = __DIR__ . '/../vendor/autoload.php';
const MIGRATIONS_DIR = __DIR__ . '/../migrations';
const CONTAINER_FILE = __DIR__ . '/../bootstrap/container.php';

require AUTOLOAD_FILE;

/* ---------------------------------------------------------------------------
 * Locking — serialize concurrent runs. See the readme's "Concurrency and locking".
 * A single lock around the WHOLE run (not per migration) keeps the check-execute-record
 * sequence atomic and preserves migration order.
 * ------------------------------------------------------------------------- */

interface Mutex
{
    /** Run $critical while holding an exclusive lock; release on return or throw. */
    public function withLock(callable $critical): mixed;
}

final class CouldNotAcquireLock extends \RuntimeException
{
}

/**
 * Database advisory lock (MySQL / MariaDB GET_LOCK). Advisory locks are session-scoped,
 * so this MUST reuse the same PDO connection that runs the migrations. Auto-released if
 * the connection drops. For single-host / file storage, use a flock-based mutex instead
 * (see the readme). For PostgreSQL, swap in pg_advisory_lock / pg_advisory_unlock.
 */
final class PdoAdvisoryMutex implements Mutex
{
    public function __construct(
        private \PDO $pdo,            // the SAME connection used to run the migrations
        private string $name = 'migrun',
        private int $timeout = 10,    // seconds to wait before giving up (block-then-fail)
    ) {
    }

    public function withLock(callable $critical): mixed
    {
        $acquire = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $acquire->execute([$this->name, $this->timeout]);
        if ((string) $acquire->fetchColumn() !== '1') {
            throw new CouldNotAcquireLock("Timed out acquiring migration lock '{$this->name}'. Another run is likely in progress.");
        }
        try {
            return $critical();
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->name]);
        }
    }
}

/**
 * Locking decorator around the runner. Only run()/rollback() mutate — status() is
 * read-only and passes through unlocked. The optional reporter is threaded through so
 * live progress still works under the lock.
 *
 * Both the decorator and $inner are typed against RunsMigrationsWithReporter, not plain
 * RunsMigrations: only the former declares the $reporter parameter. Typing $inner as
 * RunsMigrations would still run, but PHP would silently drop the forwarded reporter for
 * any implementation that does not declare it — progress output would just go quiet.
 * The decorator remains a RunsMigrations, since that interface is the parent.
 */
final readonly class LockingOrchestrator implements RunsMigrationsWithReporter
{
    public function __construct(
        private RunsMigrationsWithReporter $inner,
        private Mutex $mutex,
    ) {
    }

    public function run(?ReportsMigrations $reporter = null): array
    {
        return $this->mutex->withLock(fn() => $this->inner->run($reporter));
    }

    public function rollback(int $steps = 1, ?ReportsMigrations $reporter = null): array
    {
        return $this->mutex->withLock(fn() => $this->inner->rollback($steps, $reporter));
    }

    public function status(): array
    {
        return $this->inner->status();
    }
}

/* ---------------------------------------------------------------------------
 * Live progress reporter — prints each migration as it runs, so the ones that
 * succeeded before a failure stay visible and the failing one is named directly.
 * Extends NullReporter so it stays valid if the reporter contract ever grows.
 * ------------------------------------------------------------------------- */

final class EchoReporter extends NullReporter
{
    public bool $reportedFailure = false;

    public function __construct(private bool $useColor = false)
    {
    }

    public function starting(MigrationFile $file, Direction $direction): void
    {
        $verb = $direction === Direction::Up ? 'Migrating' : 'Reverting';
        echo "{$verb} {$file->id()} ... ";
    }

    public function finished(MigrationRun $run, Direction $direction): void
    {
        echo $this->paint(sprintf('done (%.3fs)', $run->durationSeconds), '32') . PHP_EOL;
    }

    public function failed(MigrationFile $file, Direction $direction, \Throwable $error): void
    {
        $this->reportedFailure = true;
        echo $this->paint('FAILED', '31') . PHP_EOL;
        echo '  ' . $this->paint($error->getMessage(), '31') . PHP_EOL;
    }

    private function paint(string $text, string $code): string
    {
        return $this->useColor ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}

/* ---------------------------------------------------------------------------
 * Parse arguments: separate --ansi/--no-ansi flags from positional args.
 * ------------------------------------------------------------------------- */

const USAGE = 'Usage: migrate.php [run | rollback [steps] | status | create <name>] [--ansi | --no-ansi]';

$colorMode = null; // null = auto-detect
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--ansi') {
        $colorMode = true;
    } elseif ($arg === '--no-ansi') {
        $colorMode = false;
    } elseif (str_starts_with($arg, '-')) {
        // Reject unrecognized options instead of silently treating them as the command.
        fwrite(STDERR, "Unknown option: {$arg}" . PHP_EOL);
        fwrite(STDERR, USAGE . PHP_EOL);
        exit(1);
    } else {
        $positional[] = $arg;
    }
}
$command = $positional[0] ?? 'run';

// Explicit flag wins; otherwise honor NO_COLOR (https://no-color.org); otherwise auto-detect a TTY.
$useColor = match (true) {
    $colorMode !== null => $colorMode,
    getenv('NO_COLOR') !== false => false,
    default => stream_isatty(STDOUT),
};

// Validate arguments before bootstrapping anything — a typo should not open a database
// connection, and a bad step count must be an error, not a silent "Nothing to roll back."
$steps = 1;
if ($command === 'rollback') {
    $raw = (string) ($positional[1] ?? '1');
    if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < 1) {
        fwrite(STDERR, "Invalid step count: '{$raw}'. Expected a positive integer." . PHP_EOL);
        exit(1);
    }
    $steps = (int) $raw;
}

/* ---------------------------------------------------------------------------
 * `create` — scaffold a new migration. Filesystem only; no bootstrap needed.
 * ------------------------------------------------------------------------- */

if ($command === 'create') {
    $name = $positional[1] ?? '';
    if (trim($name) === '') {
        fwrite(STDERR, 'Usage: migrate.php create <name>' . PHP_EOL);
        exit(1);
    }

    // Reject names with no usable characters (e.g. "!!!"), which would otherwise
    // produce a file named after nothing but the timestamp.
    $slug = strtolower(trim((string) preg_replace('/\W+/', '_', $name), '_'));
    if ($slug === '') {
        fwrite(STDERR, "Invalid migration name: '{$name}'. Use at least one letter or digit." . PHP_EOL);
        exit(1);
    }

    // The second is_dir() covers a concurrent create that won the race to mkdir().
    if (!is_dir(MIGRATIONS_DIR) && !mkdir(MIGRATIONS_DIR, 0775, true) && !is_dir(MIGRATIONS_DIR)) {
        fwrite(STDERR, 'Cannot create migrations directory: ' . MIGRATIONS_DIR . PHP_EOL);
        exit(1);
    }

    // UTC keeps filename ordering consistent for teams spanning timezones.
    $path = MIGRATIONS_DIR . '/' . gmdate('YmdHis') . "_{$slug}.php";
    if (file_exists($path)) {
        // The timestamp has one-second granularity, so two rapid creates can collide.
        fwrite(STDERR, "Migration already exists: {$path}" . PHP_EOL);
        exit(1);
    }

    // A migration file MUST return a migration. Method parameters are autowired by type from
    // the container — here the shared PDO connection used for storage and the advisory lock.
    $template = <<<'PHP'
<?php

declare(strict_types=1);

return new class {
    public function up(PDO $db): void
    {
        // TODO: apply the change
    }

    public function down(PDO $db): void
    {
        // TODO: revert the change (or throw or remove this method if it cannot be reversed)
    }
};

PHP;

    if (file_put_contents($path, $template) === false) {
        fwrite(STDERR, "Failed to write migration: {$path}" . PHP_EOL);
        exit(1);
    }

    echo "Created {$path}" . PHP_EOL;
    exit(0);
}

/* ---------------------------------------------------------------------------
 * Build the runner: Orchestrator (PDO storage + container) wrapped for locking.
 * Getting $pdo from the container guarantees the mutex, the storage, and the migrations
 * all share one connection — required for session-scoped advisory locks.
 * ------------------------------------------------------------------------- */

/** @var Psr\Container\ContainerInterface $container */
$container = require CONTAINER_FILE; // any PSR-11 container
$pdo = $container->get(PDO::class);

$orchestrator = (new MigrunBuilder())
    ->directory(MIGRATIONS_DIR)
    ->container($container)   // autowires migration method params by type
    ->pdoStorage($pdo)        // history stored in the same database
    ->build();

// $runner is a RunsMigrations too, so it drops in wherever a bare Orchestrator would.
// For single-host / file storage, swap PdoAdvisoryMutex for a flock-based mutex.
$runner = new LockingOrchestrator($orchestrator, new PdoAdvisoryMutex($pdo));
$reporter = new EchoReporter($useColor);

/* ---------------------------------------------------------------------------
 * Dispatch.
 * ------------------------------------------------------------------------- */

match ($command) {
    'run' => (function () use ($runner, $reporter) {
        try {
            $executed = $runner->run($reporter);
        } catch (CouldNotAcquireLock $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(2);
        } catch (\Throwable $e) {
            // A migration threw; the reporter already named it. Surface anything else.
            if (!$reporter->reportedFailure) {
                fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
            }
            exit(1);
        }
        if ($executed === []) {
            echo 'Nothing to run.' . PHP_EOL;
        }
    })(),

    'rollback' => (function () use ($runner, $reporter, $steps) {
        try {
            $reverted = $runner->rollback($steps, $reporter);
        } catch (CouldNotAcquireLock $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(2);
        } catch (\Throwable $e) {
            if (!$reporter->reportedFailure) {
                fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
            }
            exit(1);
        }
        if ($reverted === []) {
            echo 'Nothing to roll back.' . PHP_EOL;
        }
    })(),

    'status' => (function () use ($runner, $useColor) {
        $entries = $runner->status();
        if ($entries === []) {
            echo 'No migrations found.' . PHP_EOL;
            return;
        }
        $paint = fn(string $text, string $code): string => $useColor ? "\033[{$code}m{$text}\033[0m" : $text;

        // Render in UTC regardless of what the storage backend recorded, so the column
        // header stays truthful if the storage is ever swapped.
        $utc = static fn(?\DateTimeInterface $at): string => $at === null
            ? '-'
            : \DateTimeImmutable::createFromInterface($at)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');

        // Column widths sized to the widest of header label and actual content.
        $applied = array_map(fn($e) => $utc($e->appliedAt), $entries);
        $idWidth = max(max(array_map(fn($e) => strlen($e->id), $entries)), strlen('Migration ID'));
        $statusWidth = strlen('MISSING');
        $appliedWidth = max(max(array_map('strlen', $applied)), strlen('Applied at (UTC)'));
        $rule = str_repeat('-', $idWidth + 2 + $statusWidth + 2 + $appliedWidth);

        echo sprintf("%-{$idWidth}s  %-{$statusWidth}s  %s", 'Migration ID', 'Status', 'Applied at (UTC)') . PHP_EOL;
        echo $rule . PHP_EOL;

        $up = $down = 0;
        $missingSince = null;
        foreach ($entries as $entry) {
            [$label, $color] = match ($entry->state) {
                MigrationState::Applied => ['up', '32'],      // green
                MigrationState::Pending => ['down', '33'],    // yellow
                MigrationState::Missing => ['MISSING', '31'], // red
            };
            $up += MigrationState::Applied === $entry->state ? 1 : 0;
            $down += MigrationState::Pending === $entry->state ? 1 : 0;
            if (MigrationState::Missing === $entry->state && $entry->appliedAt !== null) {
                // Earliest applied_at among the missing files — the point from which history has gaps.
                if ($missingSince === null || $entry->appliedAt < $missingSince) {
                    $missingSince = $entry->appliedAt;
                }
            }
            // Pad before coloring so the escape codes do not throw off column alignment.
            echo sprintf(
                "%-{$idWidth}s  %s  %s",
                $entry->id,
                $paint(str_pad($label, $statusWidth), $color),
                $utc($entry->appliedAt),
            ) . PHP_EOL;
        }
        echo $rule . PHP_EOL;

        if ($missingSince !== null) {
            echo $paint('WARNING! Some migration files missing since ' . $utc($missingSince) . '.', '31') . PHP_EOL;
        }
        echo "Total: {$up} up, {$down} down" . PHP_EOL;

        // No exit() here on purpose: `status` is a report, not a CI gate, so pending
        // or missing migrations still leave the exit code at 0.
    })(),

    default => (function () use ($command) {
        fwrite(STDERR, "Unknown command: {$command}" . PHP_EOL);
        fwrite(STDERR, USAGE . PHP_EOL);
        exit(1);
    })(),
};
