<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use DateTimeImmutable;
use Dakujem\Migrun\Exception\MigrationNotFoundException;
use Dakujem\Migrun\Finder\DirectoryFinder;
use Dakujem\Migrun\MigrationHistoryEntry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DirectoryFinderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrun_finder_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // list()
    // -------------------------------------------------------------------------

    public function testListReturnsEmptyForEmptyDirectory(): void
    {
        $finder = new DirectoryFinder($this->dir);
        self::assertSame([], iterator_to_array($finder->list()));
    }

    public function testListFindsAllPhpFiles(): void
    {
        // Any .php file is accepted — no format restriction
        file_put_contents("{$this->dir}/create_users.php", '<?php');
        file_put_contents("{$this->dir}/001_create_users.php", '<?php');

        $finder = new DirectoryFinder($this->dir);
        $found = iterator_to_array($finder->list());

        self::assertCount(2, $found);
    }

    public function testListIgnoresNonPhpFiles(): void
    {
        file_put_contents("{$this->dir}/migration.sql", '-- sql');
        file_put_contents("{$this->dir}/notes.txt", 'notes');
        file_put_contents("{$this->dir}/20240101_create_users.php", '<?php');

        $finder = new DirectoryFinder($this->dir);
        $found = iterator_to_array($finder->list());

        self::assertCount(1, $found);
        self::assertSame('20240101_create_users', $found[0]->id());
    }

    public function testListSortsByIdAscending(): void
    {
        file_put_contents("{$this->dir}/20240115_090000_beta.php", '<?php');
        file_put_contents("{$this->dir}/20240101_120000_alpha.php", '<?php');
        file_put_contents("{$this->dir}/20240120_080000_gamma.php", '<?php');

        $finder = new DirectoryFinder($this->dir);
        $found = iterator_to_array($finder->list());

        self::assertCount(3, $found);
        self::assertSame('20240101_120000_alpha', $found[0]->id());
        self::assertSame('20240115_090000_beta', $found[1]->id());
        self::assertSame('20240120_080000_gamma', $found[2]->id());
    }

    public function testListThrowsForNonExistentDirectory(): void
    {
        $finder = new DirectoryFinder('/does/not/exist');

        $this->expectException(RuntimeException::class);
        iterator_to_array($finder->list());
    }

    public function testListNonRecursiveDoesNotDescendIntoSubdirectories(): void
    {
        file_put_contents("{$this->dir}/20240101_120000_root.php", '<?php');
        mkdir("{$this->dir}/sub");
        file_put_contents("{$this->dir}/sub/20240201_120000_nested.php", '<?php');

        $finder = new DirectoryFinder($this->dir); // recursive defaults to false
        $found = iterator_to_array($finder->list());

        self::assertCount(1, $found);
        self::assertSame('20240101_120000_root', $found[0]->id());
    }

    public function testListRecursiveFindsFilesInSubdirectories(): void
    {
        file_put_contents("{$this->dir}/20240101_120000_root.php", '<?php');
        mkdir("{$this->dir}/sub");
        file_put_contents("{$this->dir}/sub/20240201_120000_nested.php", '<?php');

        $finder = new DirectoryFinder($this->dir, recursive: true);
        $found = iterator_to_array($finder->list());

        self::assertCount(2, $found);
        // IDs include the relative subpath
        self::assertSame('20240101_120000_root', $found[0]->id());
        self::assertSame('sub' . DIRECTORY_SEPARATOR . '20240201_120000_nested', $found[1]->id());
    }

    public function testListRecursiveSortsGloballyAcrossSubdirectories(): void
    {
        mkdir("{$this->dir}/2024/01", recursive: true);
        mkdir("{$this->dir}/2024/03", recursive: true);
        file_put_contents("{$this->dir}/2024/03/20240315_080000_gamma.php", '<?php');
        file_put_contents("{$this->dir}/2024/01/20240101_120000_alpha.php", '<?php');
        file_put_contents("{$this->dir}/20240201_090000_beta.php", '<?php');

        $finder = new DirectoryFinder($this->dir, recursive: true);
        $found = iterator_to_array($finder->list());

        self::assertCount(3, $found);
        // Lexicographic sort: "2024/..." < "20240201..." because '/' < '2' in ASCII? No:
        // '/' is 0x2F, digits start at 0x30 — so subdirectory paths sort before flat ones.
        // Actual order depends on the paths; assert all three are present and sorted.
        $ids = array_map(fn($f) => $f->id(), $found);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids, 'Results must be sorted ascending by ID');
    }

    // -------------------------------------------------------------------------
    // ID derivation
    // -------------------------------------------------------------------------

    public function testIdIsRelativePathStemWithoutPhpExtension(): void
    {
        file_put_contents("{$this->dir}/20240101_create_users.php", '<?php');

        $finder = new DirectoryFinder($this->dir);
        $found = iterator_to_array($finder->list());

        self::assertCount(1, $found);
        self::assertSame('20240101_create_users', $found[0]->id());
        self::assertStringEndsWith('20240101_create_users.php', $found[0]->path());
    }

    public function testIdForRecursiveFileIncludesRelativeSubdirectory(): void
    {
        mkdir("{$this->dir}/v2");
        file_put_contents("{$this->dir}/v2/add_index.php", '<?php');

        $finder = new DirectoryFinder($this->dir, recursive: true);
        $found = iterator_to_array($finder->list());

        self::assertCount(1, $found);
        self::assertSame('v2' . DIRECTORY_SEPARATOR . 'add_index', $found[0]->id());
    }

    // -------------------------------------------------------------------------
    // find()
    // -------------------------------------------------------------------------

    public function testFindReturnsRequestedMigrationsIndexedById(): void
    {
        file_put_contents("{$this->dir}/20240101_120000_alpha.php", '<?php');
        file_put_contents("{$this->dir}/20240201_090000_beta.php", '<?php');
        file_put_contents("{$this->dir}/20240315_080000_gamma.php", '<?php');

        $finder = new DirectoryFinder($this->dir);
        $found = $finder->find(['20240201_090000_beta', '20240101_120000_alpha']);

        self::assertCount(2, $found);
        self::assertArrayHasKey('20240201_090000_beta', $found);
        self::assertArrayHasKey('20240101_120000_alpha', $found);
        self::assertSame('20240201_090000_beta', $found['20240201_090000_beta']->id());
        self::assertSame('20240101_120000_alpha', $found['20240101_120000_alpha']->id());
    }

    public function testFindAcceptsMigrationHistoryEntryInstances(): void
    {
        file_put_contents("{$this->dir}/20240101_120000_alpha.php", '<?php');

        $entry = new MigrationHistoryEntry(
            id: '20240101_120000_alpha',
            at: new DateTimeImmutable('2024-01-01 12:00:00'),
        );

        $finder = new DirectoryFinder($this->dir);
        $found = $finder->find([$entry]);

        self::assertArrayHasKey('20240101_120000_alpha', $found);
    }

    public function testFindThrowsWhenMigrationNotFound(): void
    {
        file_put_contents("{$this->dir}/20240101_120000_alpha.php", '<?php');

        $finder = new DirectoryFinder($this->dir);

        $this->expectException(MigrationNotFoundException::class);
        $finder->find(['20240101_120000_alpha', '99991231_235959_ghost']);
    }

    public function testFindThrowsForNonExistentDirectory(): void
    {
        $finder = new DirectoryFinder('/does/not/exist');

        $this->expectException(RuntimeException::class);
        $finder->find(['20240101_120000_alpha']);
    }

    public function testFindPreservesInputOrder(): void
    {
        file_put_contents("{$this->dir}/20240101_120000_alpha.php", '<?php');
        file_put_contents("{$this->dir}/20240201_090000_beta.php", '<?php');
        file_put_contents("{$this->dir}/20240315_080000_gamma.php", '<?php');

        $finder = new DirectoryFinder($this->dir);
        // Request in reverse order
        $found = $finder->find([
            '20240315_080000_gamma',
            '20240101_120000_alpha',
            '20240201_090000_beta',
        ]);

        self::assertSame(
            ['20240315_080000_gamma', '20240101_120000_alpha', '20240201_090000_beta'],
            array_keys($found),
        );
    }

    public function testFindWorksRecursively(): void
    {
        mkdir("{$this->dir}/sub");
        file_put_contents("{$this->dir}/sub/20240201_120000_nested.php", '<?php');

        $finder = new DirectoryFinder($this->dir, recursive: true);
        $id = 'sub' . DIRECTORY_SEPARATOR . '20240201_120000_nested';
        $found = $finder->find([$id]);

        self::assertArrayHasKey($id, $found);
    }
}
