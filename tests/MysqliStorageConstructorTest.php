<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Storage\MysqliStorage;
use InvalidArgumentException;
use mysqli;
use PHPUnit\Framework\TestCase;

/**
 * Constructor validation tests for MysqliStorage.
 *
 * These do NOT require a live MySQL connection: the table-name guard fires
 * before any network I/O, so a mock mysqli object is sufficient.
 */
final class MysqliStorageConstructorTest extends TestCase
{
    private function stubMysqli(): mysqli
    {
        return $this->createMock(mysqli::class);
    }

    public function testInvalidTableNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MysqliStorage($this->stubMysqli(), 'bad-name!');
    }

    public function testTableNameWithSpaceThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MysqliStorage($this->stubMysqli(), 'my table');
    }

    public function testTableNameStartingWithDigitThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MysqliStorage($this->stubMysqli(), '1migrations');
    }

    public function testValidTableNameDoesNotThrow(): void
    {
        $storage = new MysqliStorage($this->stubMysqli(), 'schema_history');
        self::assertInstanceOf(MysqliStorage::class, $storage);
    }

    public function testDefaultTableNameDoesNotThrow(): void
    {
        $storage = new MysqliStorage($this->stubMysqli());
        self::assertInstanceOf(MysqliStorage::class, $storage);
    }
}
