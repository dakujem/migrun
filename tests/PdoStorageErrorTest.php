<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Storage\PdoStorage;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the PDO error-throw branches in PdoStorage that cannot be reached
 * with a real SQLite connection (where operations always succeed).
 *
 * A mock PDO is used to simulate driver-level failures.
 */
final class PdoStorageErrorTest extends TestCase
{
    // -----------------------------------------------------------------------
    // getApplied — query() returns false
    // -----------------------------------------------------------------------

    public function testGetAppliedThrowsWhenQueryReturnsFalse(): void
    {
        $pdo = $this->createMock(PDO::class);

        // exec() for CREATE TABLE must succeed first.
        $pdo->method('exec')->willReturn(0);

        // query() for SELECT returns false → should throw.
        $pdo->method('query')->willReturn(false);

        $storage = new PdoStorage($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not query/');
        iterator_to_array($storage->getApplied());
    }

    // -----------------------------------------------------------------------
    // isApplied — prepare() returns false
    // -----------------------------------------------------------------------

    public function testIsAppliedThrowsWhenPrepareReturnsFalse(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturn(false);

        $storage = new PdoStorage($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not query/');
        $storage->isApplied('20240101_test');
    }

    // -----------------------------------------------------------------------
    // isApplied — execute() returns false
    // -----------------------------------------------------------------------

    public function testIsAppliedThrowsWhenExecuteReturnsFalse(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturn($stmt);

        $storage = new PdoStorage($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not query/');
        $storage->isApplied('20240101_test');
    }

    // -----------------------------------------------------------------------
    // markApplied — INSERT prepare() returns false
    // -----------------------------------------------------------------------

    public function testMarkAppliedThrowsWhenInsertPrepareReturnsFalse(): void
    {
        // First prepare call (for isApplied SELECT) must succeed and return not-found.
        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetchColumn')->willReturn(false); // not applied yet

        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturn(0);

        // First prepare → SELECT (isApplied check), second prepare → INSERT (fails).
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, false);

        $storage = new PdoStorage($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not insert/');
        $storage->markApplied('20240101_test');
    }

    // -----------------------------------------------------------------------
    // markApplied — INSERT execute() returns false
    // -----------------------------------------------------------------------

    public function testMarkAppliedThrowsWhenInsertExecuteReturnsFalse(): void
    {
        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetchColumn')->willReturn(false);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $insertStmt);

        $storage = new PdoStorage($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not insert/');
        $storage->markApplied('20240101_test');
    }

    // -----------------------------------------------------------------------
    // markReverted — prepare() returns false
    // -----------------------------------------------------------------------

    public function testMarkRevertedThrowsWhenPrepareReturnsFalse(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturn(false);

        $storage = new PdoStorage($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not delete/');
        $storage->markReverted('20240101_test');
    }

    // -----------------------------------------------------------------------
    // markReverted — execute() returns false
    // -----------------------------------------------------------------------

    public function testMarkRevertedThrowsWhenExecuteReturnsFalse(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturn($stmt);

        $storage = new PdoStorage($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not delete/');
        $storage->markReverted('20240101_test');
    }

    // -----------------------------------------------------------------------
    // rowToEntry — unexpected row structure (not a keyed array)
    // -----------------------------------------------------------------------

    public function testGetAppliedThrowsOnUnexpectedRowStructure(): void
    {
        $resultStmt = $this->createMock(PDOStatement::class);
        // fetchAll returns a row that is a plain scalar string, not a keyed array.
        $resultStmt->method('fetchAll')->willReturn(['not-a-keyed-array']);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('query')->willReturn($resultStmt);

        $storage = new PdoStorage($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unexpected row structure/');
        iterator_to_array($storage->getApplied());
    }
}
