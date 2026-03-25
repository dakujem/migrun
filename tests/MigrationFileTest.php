<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\MigrationFile;
use PHPUnit\Framework\TestCase;

final class MigrationFileTest extends TestCase
{
    public function testStoresPathAndId(): void
    {
        $file = new MigrationFile(
            path: '/migrations/20240101_120000_create_users.php',
            id: '20240101_120000_create_users',
        );

        self::assertSame('/migrations/20240101_120000_create_users.php', $file->path());
        self::assertSame('20240101_120000_create_users', $file->id());
    }

    public function testIdSortingIsLexicographic(): void
    {
        $first  = new MigrationFile('/m/20240101_alpha.php', '20240101_alpha');
        $second = new MigrationFile('/m/20240115_beta.php',  '20240115_beta');

        self::assertLessThan(0, $first->id() <=> $second->id());
    }
}
