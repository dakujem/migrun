<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Direction;
use Dakujem\Migrun\Exception\InvalidMigrationException;
use Dakujem\Migrun\Exception\NoRollbackException;
use Dakujem\Migrun\Executor\ContainerInvoker;
use Dakujem\Migrun\Executor\Executor;
use Dakujem\Migrun\Executor\InvokesCallable;
use Dakujem\Migrun\Executor\TrivialInvoker;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\Migration;
use Dakujem\Migrun\ReversibleMigration;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ExecutorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrun_executor_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*.php") as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function migrationFile(string $filename): MigrationFile
    {
        $path = "{$this->dir}/{$filename}";
        $id = pathinfo($filename, PATHINFO_FILENAME);
        return new MigrationFile(path: $path, id: $id);
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

    private function emptyInvoker(): InvokesCallable
    {
        return new ContainerInvoker($this->emptyContainer());
    }

    public function testExecutesCallableUp(): void
    {
        $flag = $this->dir . '/executed.flag';
        file_put_contents(
            "{$this->dir}/20240101_120000_test.php",
            "<?php return function() { file_put_contents('" . addslashes($flag) . "', '1'); };",
        );

        $executor = new Executor($this->emptyInvoker());
        $executor->execute($this->migrationFile('20240101_120000_test.php'), Direction::Up);

        self::assertFileExists($flag);
        unlink($flag);
    }

    public function testCallableDownThrowsNoRollbackException(): void
    {
        file_put_contents(
            "{$this->dir}/20240101_120000_norollback.php",
            '<?php return function() {};',
        );

        $executor = new Executor($this->emptyInvoker());

        $this->expectException(NoRollbackException::class);
        $executor->execute($this->migrationFile('20240101_120000_norollback.php'), Direction::Down);
    }

    public function testExecutesMigrationInterfaceUp(): void
    {
        $flag = $this->dir . '/up.flag';
        file_put_contents(
            "{$this->dir}/20240101_120000_interface.php",
            "<?php
use Dakujem\\Migrun\\ReversibleMigration;
return new class implements ReversibleMigration {
    public function up(): void { file_put_contents('" . addslashes($flag) . "', 'up'); }
    public function down(): void { file_put_contents('" . addslashes($flag) . "', 'down'); }
};",
        );

        $executor = new Executor($this->emptyInvoker());
        $executor->execute($this->migrationFile('20240101_120000_interface.php'), Direction::Up);

        self::assertFileExists($flag);
        self::assertSame('up', file_get_contents($flag));
        unlink($flag);
    }

    public function testExecutesMigrationInterfaceDown(): void
    {
        $flag = $this->dir . '/down.flag';
        file_put_contents(
            "{$this->dir}/20240101_120000_interface_down.php",
            "<?php
use Dakujem\\Migrun\\ReversibleMigration;
return new class implements ReversibleMigration {
    public function up(): void { file_put_contents('" . addslashes($flag) . "', 'up'); }
    public function down(): void { file_put_contents('" . addslashes($flag) . "', 'down'); }
};",
        );

        $executor = new Executor($this->emptyInvoker());
        $executor->execute($this->migrationFile('20240101_120000_interface_down.php'), Direction::Down);

        self::assertFileExists($flag);
        self::assertSame('down', file_get_contents($flag));
        unlink($flag);
    }

    public function testMigrationInterfaceWithoutReversibleThrowsOnDown(): void
    {
        $flag = $this->dir . '/forward_only.flag';
        file_put_contents(
            "{$this->dir}/20240101_120000_forward_only.php",
            "<?php
use Dakujem\\Migrun\\Migration;
return new class implements Migration {
    public function up(): void { file_put_contents('" . addslashes($flag) . "', 'up'); }
};",
        );

        $executor = new Executor($this->emptyInvoker());

        $this->expectException(NoRollbackException::class);
        $executor->execute($this->migrationFile('20240101_120000_forward_only.php'), Direction::Down);
    }

    public function testThrowsForInvalidReturnValue(): void
    {
        file_put_contents(
            "{$this->dir}/20240101_120000_invalid.php",
            '<?php return "not a migration";',
        );

        $executor = new Executor($this->emptyInvoker());

        $this->expectException(InvalidMigrationException::class);
        $executor->execute($this->migrationFile('20240101_120000_invalid.php'), Direction::Up);
    }

    public function testAutowiresCallableFromInvoker(): void
    {
        $flag = $this->dir . '/autowire.flag';

        file_put_contents(
            "{$this->dir}/20240101_120000_autowire.php",
            "<?php return function(\\Dakujem\\Migrun\\Tests\\FakeService \$svc): void {
                file_put_contents('" . addslashes($flag) . "', \$svc->value);
            };",
        );

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === 'Dakujem\\Migrun\\Tests\\FakeService') {
                    return new FakeService();
                }
                throw new \RuntimeException("Not found: $id");
            }

            public function has(string $id): bool
            {
                return $id === 'Dakujem\\Migrun\\Tests\\FakeService';
            }
        };

        $executor = new Executor(new ContainerInvoker($container));
        $executor->execute($this->migrationFile('20240101_120000_autowire.php'), Direction::Up);

        self::assertFileExists($flag);
        self::assertSame('injected', file_get_contents($flag));
        unlink($flag);
    }

    public function testAutowiresMigrationUpMethodOptionalParam(): void
    {
        $flag = $this->dir . '/autowire_up.flag';

        file_put_contents(
            "{$this->dir}/20240101_120000_autowire_up.php",
            "<?php
use Dakujem\\Migrun\\Migration;
return new class implements Migration {
    public function up(\\Dakujem\\Migrun\\Tests\\FakeService \$svc = new \\Dakujem\\Migrun\\Tests\\FakeService()): void {
        file_put_contents('" . addslashes($flag) . "', \$svc->value);
    }
};",
        );

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === 'Dakujem\\Migrun\\Tests\\FakeService') {
                    $svc = new FakeService();
                    $svc->value = 'injected';
                    return $svc;
                }
                throw new \RuntimeException("Not found: $id");
            }

            public function has(string $id): bool
            {
                return $id === 'Dakujem\\Migrun\\Tests\\FakeService';
            }
        };

        $executor = new Executor(new ContainerInvoker($container));
        $executor->execute($this->migrationFile('20240101_120000_autowire_up.php'), Direction::Up);

        self::assertFileExists($flag);
        self::assertSame('injected', file_get_contents($flag));
        unlink($flag);
    }

    public function testAutowiresReversibleMigrationDownMethodOptionalParam(): void
    {
        $flag = $this->dir . '/autowire_down.flag';

        file_put_contents(
            "{$this->dir}/20240101_120000_autowire_down.php",
            "<?php
use Dakujem\\Migrun\\ReversibleMigration;
return new class implements ReversibleMigration {
    public function up(): void {}
    public function down(\\Dakujem\\Migrun\\Tests\\FakeService \$svc = new \\Dakujem\\Migrun\\Tests\\FakeService()): void {
        file_put_contents('" . addslashes($flag) . "', \$svc->value);
    }
};",
        );

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === 'Dakujem\\Migrun\\Tests\\FakeService') {
                    $svc = new FakeService();
                    $svc->value = 'injected';
                    return $svc;
                }
                throw new \RuntimeException("Not found: $id");
            }

            public function has(string $id): bool
            {
                return $id === 'Dakujem\\Migrun\\Tests\\FakeService';
            }
        };

        $executor = new Executor(new ContainerInvoker($container));
        $executor->execute($this->migrationFile('20240101_120000_autowire_down.php'), Direction::Down);

        self::assertFileExists($flag);
        self::assertSame('injected', file_get_contents($flag));
        unlink($flag);
    }

    public function testExecutesDuckTypeUpOnly(): void
    {
        $flag = $this->dir . '/duck_up.flag';
        file_put_contents(
            "{$this->dir}/20240101_120000_duck_up.php",
            "<?php
return new class {
    public function up(): void { file_put_contents('" . addslashes($flag) . "', 'up'); }
};",
        );

        $executor = new Executor($this->emptyInvoker());
        $executor->execute($this->migrationFile('20240101_120000_duck_up.php'), Direction::Up);

        self::assertFileExists($flag);
        self::assertSame('up', file_get_contents($flag));
        unlink($flag);
    }

    public function testDuckTypeUpOnlyThrowsOnDown(): void
    {
        file_put_contents(
            "{$this->dir}/20240101_120000_duck_up_nodown.php",
            "<?php
return new class {
    public function up(): void {}
};",
        );

        $executor = new Executor($this->emptyInvoker());

        $this->expectException(NoRollbackException::class);
        $executor->execute($this->migrationFile('20240101_120000_duck_up_nodown.php'), Direction::Down);
    }

    public function testExecutesDuckTypeUpAndDown(): void
    {
        $flag = $this->dir . '/duck_updown.flag';
        file_put_contents(
            "{$this->dir}/20240101_120000_duck_updown.php",
            "<?php
return new class {
    public function up(): void { file_put_contents('" . addslashes($flag) . "', 'up'); }
    public function down(): void { file_put_contents('" . addslashes($flag) . "', 'down'); }
};",
        );

        $executor = new Executor($this->emptyInvoker());

        $executor->execute($this->migrationFile('20240101_120000_duck_updown.php'), Direction::Up);
        self::assertSame('up', file_get_contents($flag));

        $executor->execute($this->migrationFile('20240101_120000_duck_updown.php'), Direction::Down);
        self::assertSame('down', file_get_contents($flag));

        unlink($flag);
    }

    public function testDuckTypeUpTakesPrecedenceOverInvoke(): void
    {
        $flag = $this->dir . '/duck_invoke.flag';
        file_put_contents(
            "{$this->dir}/20240101_120000_duck_invoke.php",
            "<?php
return new class {
    public function up(): void { file_put_contents('" . addslashes($flag) . "', 'up'); }
    public function down(): void { file_put_contents('" . addslashes($flag) . "', 'down'); }
    public function __invoke(): void { file_put_contents('" . addslashes($flag) . "', 'invoke'); }
};",
        );

        $executor = new Executor($this->emptyInvoker());

        $executor->execute($this->migrationFile('20240101_120000_duck_invoke.php'), Direction::Up);
        self::assertSame('up', file_get_contents($flag));

        $executor->execute($this->migrationFile('20240101_120000_duck_invoke.php'), Direction::Down);
        self::assertSame('down', file_get_contents($flag));

        unlink($flag);
    }

    public function testTrivialInvokerInvokesCallableWithNoArguments(): void
    {
        $flag = $this->dir . '/trivial.flag';

        file_put_contents(
            "{$this->dir}/20240101_120000_trivial.php",
            "<?php return function() { file_put_contents('" . addslashes($flag) . "', 'ok'); };",
        );

        $executor = new Executor(new TrivialInvoker());
        $executor->execute($this->migrationFile('20240101_120000_trivial.php'), Direction::Up);

        self::assertFileExists($flag);
        self::assertSame('ok', file_get_contents($flag));
        unlink($flag);
    }

    public function testTrivialInvokerReturnsCallableReturnValue(): void
    {
        $invoker = new TrivialInvoker();
        $result = $invoker->invoke(fn() => 'hello');
        self::assertSame('hello', $result);
    }

    public function testAcceptsCustomInvoker(): void
    {
        $flag = $this->dir . '/custom_invoker.flag';

        file_put_contents(
            "{$this->dir}/20240101_120000_custom.php",
            "<?php return function(string \$msg): void {
                file_put_contents('" . addslashes($flag) . "', \$msg);
            };",
        );

        // A custom invoker that supplies a hard-coded argument
        $invoker = new class implements InvokesCallable {
            public function invoke(callable $fn): mixed
            {
                return $fn('hello-from-custom-invoker');
            }
        };

        $executor = new Executor($invoker);
        $executor->execute($this->migrationFile('20240101_120000_custom.php'), Direction::Up);

        self::assertFileExists($flag);
        self::assertSame('hello-from-custom-invoker', file_get_contents($flag));
        unlink($flag);
    }
}

// Fixture class used in autowiring tests
class FakeService
{
    public string $value = 'injected';
}
