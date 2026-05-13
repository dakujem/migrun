<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Storage\JsonFileStorage;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the file I/O error-throw branches in JsonFileStorage that require
 * simulated filesystem failures (unreadable files, unwritable directories).
 *
 * Uses vfsStream so that no real filesystem permissions need to be changed.
 */
final class JsonFileStorageErrorTest extends TestCase
{
    private vfsStreamDirectory $root;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('migrun');
    }

    // -----------------------------------------------------------------------
    // file_get_contents returns false — unreadable file (line 96)
    // -----------------------------------------------------------------------

    public function testGetAppliedThrowsWhenFileIsUnreadable(): void
    {
        $file = vfsStream::newFile('history.json', 0000)
            ->withContent('[]')
            ->at($this->root);

        $storage = new JsonFileStorage($file->url());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not read/');
        $storage->getApplied();
    }

    // -----------------------------------------------------------------------
    // mkdir returns false — unwritable parent directory (line 138)
    // -----------------------------------------------------------------------

    public function testMarkAppliedThrowsWhenDirectoryCannotBeCreated(): void
    {
        // Make root read-only so mkdir for a sub-directory fails.
        $this->root->chmod(0555);

        $path = vfsStream::url('migrun/subdir/history.json');
        $storage = new JsonFileStorage($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not create directory/');
        $storage->markApplied('20240101_init');
    }

    // -----------------------------------------------------------------------
    // file_put_contents returns false — unwritable file (line 156)
    // -----------------------------------------------------------------------

    public function testMarkAppliedThrowsWhenFileIsNotWritable(): void
    {
        // Create an existing, unwritable history file.
        vfsStream::newFile('history.json', 0444)
            ->withContent("[]")
            ->at($this->root);

        $storage = new JsonFileStorage(vfsStream::url('migrun/history.json'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not write/');
        $storage->markApplied('20240101_init');
    }
}
