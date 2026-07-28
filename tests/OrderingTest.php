<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Finder\DirectoryFinder;
use Dakujem\Migrun\MigrationFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Migration ordering.
 *
 * IDs are compared byte by byte (strcmp). Before 1.0.1 they were compared with PHP's
 * `<=>`, which compares two numeric strings as numbers — that is neither a total order
 * (distinct IDs could compare equal) nor transitive (once numeric and non-numeric IDs
 * are mixed), so the resulting sort order was undefined.
 *
 * The old behaviour is pinned in these tests via a plain `<=>`, so the regression stays
 * visible rather than being taken on trust.
 */
final class OrderingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrun_order_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @param string[] $ids */
    private function makeFiles(array $ids): void
    {
        foreach ($ids as $id) {
            file_put_contents($this->dir . "/{$id}.php", '<?php return function () {};');
        }
    }

    /** @return string[] */
    private function listedIds(): array
    {
        return array_map(
            fn(MigrationFile $m) => $m->id(),
            iterator_to_array((new DirectoryFinder($this->dir))->list(), false),
        );
    }

    // -------------------------------------------------------------------------
    // The comparison rule
    // -------------------------------------------------------------------------

    /**
     * A total order must never call two distinct IDs equal. The old comparison did.
     */
    #[DataProvider('provideNumericallyEqualPairs')]
    public function testDistinctIdsNeverCompareEqual(string $a, string $b): void
    {
        self::assertNotSame(0, strcmp($a, $b), "'{$a}' and '{$b}' must not compare equal");
        self::assertNotSame(0, strcmp($b, $a));

        // Antisymmetric.
        self::assertSame(strcmp($a, $b) <=> 0, -(strcmp($b, $a) <=> 0));

        // The old behaviour — pinned so the regression is visible.
        self::assertSame(0, $a <=> $b, 'pre-1.0.1 comparison considered these equal');
    }

    public static function provideNumericallyEqualPairs(): iterable
    {
        yield 'leading zero'        => ['09', '9'];
        yield 'leading zero, wide'  => ['010', '10'];
        yield 'scientific notation' => ['1e2', '100'];
        yield 'float vs int'        => ['1.0', '1'];
    }

    /**
     * Digits are compared as characters, not values: '10' precedes '9' because
     * '1' precedes '9'.
     */
    public function testUnpaddedNumbersSortByBytesNotByValue(): void
    {
        self::assertLessThan(0, strcmp('10', '9'));
        self::assertLessThan(0, strcmp('09', '10'));
        self::assertGreaterThan(0, strcmp('9', '10'));

        // Documented expectation: 09 < 10 < 9
        $ids = ['9', '10', '09'];
        usort($ids, fn(string $a, string $b) => strcmp($a, $b));
        self::assertSame(['09', '10', '9'], $ids);
    }

    /**
     * Transitivity: a < b and b < c must imply a < c. The old comparison broke this for
     * '2' < '10' (numeric), '10' < '1a' (string), yet '1a' < '2' (string).
     */
    public function testOrderIsTransitiveWhereTheOldOneWasNot(): void
    {
        $sorted = ['2', '10', '1a'];
        usort($sorted, fn(string $a, string $b) => strcmp($a, $b));
        self::assertSame(['10', '1a', '2'], $sorted);

        // Verify the property directly over the permutations of the triple.
        foreach ([['2', '10', '1a'], ['1a', '10', '2'], ['10', '2', '1a']] as [$x, $y, $z]) {
            if ((strcmp($x, $y) <=> 0) < 0 && (strcmp($y, $z) <=> 0) < 0) {
                self::assertLessThan(0, strcmp($x, $z), 'transitivity violated');
            }
        }

        // The old comparison really was intransitive — pinned for contrast.
        self::assertLessThan(0, '2' <=> '10');      // numeric: 2 < 10
        self::assertLessThan(0, '10' <=> '1a');     // string:  '10' < '1a'
        self::assertGreaterThan(0, '2' <=> '1a');   // but '2' > '1a' — no total order
    }

    /**
     * The recommended naming schemes must be unaffected by the change, otherwise this
     * would not be a safe fix to ship as a patch release.
     *
     * @param string[] $ids
     */
    #[DataProvider('provideUnaffectedSchemes')]
    public function testRecommendedSchemesOrderIdenticallyUnderBothComparisons(array $ids): void
    {
        $byBytes = $ids;
        usort($byBytes, fn(string $a, string $b) => strcmp($a, $b));

        $byLegacy = $ids;
        usort($byLegacy, fn(string $a, string $b) => $a <=> $b);

        self::assertSame($byBytes, $byLegacy);
    }

    public static function provideUnaffectedSchemes(): iterable
    {
        yield 'timestamps' => [[
            '20240115_093000_add_email_index',
            '20240101_120000_create_users',
            '20260505_143544_backfill',
        ]];
        yield 'bare timestamps' => [['20260505143544', '20240101120000']];
        yield 'zero padded' => [['010', '001', '009', '002']];
        yield 'padded with names' => [['003_c', '001_a', '010_d', '002_b']];
        yield 'unpadded but prefixed' => [['v1', 'v10', 'v2', 'v9']]; // non-numeric: byte-wise already
    }

    // -------------------------------------------------------------------------
    // DirectoryFinder
    // -------------------------------------------------------------------------

    public function testFinderSortsLexicographically(): void
    {
        $this->makeFiles(['9', '10', '2']);

        self::assertSame(['10', '2', '9'], $this->listedIds());
    }

    public function testFinderOrdersTimestampedMigrationsChronologically(): void
    {
        $this->makeFiles([
            '20240115_093000_beta',
            '20240101_120000_alpha',
            '20260505_143544_gamma',
        ]);

        self::assertSame(
            ['20240101_120000_alpha', '20240115_093000_beta', '20260505_143544_gamma'],
            $this->listedIds(),
        );
    }

    public function testFinderOrdersZeroPaddedIdsByValue(): void
    {
        $this->makeFiles(['001', '002', '009', '010', '011']);

        self::assertSame(['001', '002', '009', '010', '011'], $this->listedIds());
    }

    /**
     * Leading-zero IDs used to compare equal, leaving their relative order undefined.
     * They must now be ordered deterministically.
     */
    public function testFinderOrdersNumericallyEqualIdsDeterministically(): void
    {
        $this->makeFiles(['9', '09', '010', '10']);

        // Byte order: '0' < '1' < '9', so 010 < 09 < 10 < 9.
        self::assertSame(['010', '09', '10', '9'], $this->listedIds());
    }
}
