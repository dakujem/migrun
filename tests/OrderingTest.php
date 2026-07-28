<?php

declare(strict_types=1);

namespace Dakujem\Migrun\Tests;

use Dakujem\Migrun\Finder\DirectoryFinder;
use Dakujem\Migrun\LexicographicOrder;
use Dakujem\Migrun\MigrationFile;
use Dakujem\Migrun\NumericOrder;
use Dakujem\Migrun\OrdersMigrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Migration ordering.
 *
 * The default ordering is byte-by-byte (strcmp). Before v1.1 it was PHP's `<=>`,
 * which compares two numeric strings as numbers — that is neither a total order
 * (distinct IDs could compare equal) nor transitive (once numeric and non-numeric
 * IDs are mixed), so the resulting sort was undefined.
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
    private function listedIds(?OrdersMigrations $order = null): array
    {
        $finder = $order === null
            ? new DirectoryFinder($this->dir)
            : new DirectoryFinder($this->dir, false, $order);

        return array_map(
            fn(MigrationFile $m) => $m->id(),
            iterator_to_array($finder->list(), false),
        );
    }

    // -------------------------------------------------------------------------
    // LexicographicOrder — the default
    // -------------------------------------------------------------------------

    /**
     * The pre-1.1 comparison returned 0 for these distinct pairs, which made the sort
     * order undefined. A total order must never call two different IDs equal.
     */
    #[DataProvider('provideNumericallyEqualPairs')]
    public function testDistinctIdsNeverCompareEqual(string $a, string $b): void
    {
        $order = new LexicographicOrder();

        self::assertNotSame(0, $order->compare($a, $b), "'{$a}' and '{$b}' must not compare equal");
        self::assertNotSame(0, $order->compare($b, $a));

        // Antisymmetric.
        self::assertSame(
            $order->compare($a, $b) <=> 0,
            -($order->compare($b, $a) <=> 0),
        );

        // The old behaviour, pinned so the regression is visible.
        self::assertSame(0, (new NumericOrder())->compare($a, $b));
    }

    public static function provideNumericallyEqualPairs(): iterable
    {
        yield 'leading zero'        => ['09', '9'];
        yield 'leading zero, wide'  => ['010', '10'];
        yield 'scientific notation' => ['1e2', '100'];
        yield 'float vs int'        => ['1.0', '1'];
    }

    /**
     * Byte order does not interpret digits as numbers: '10' precedes '9'
     * because '1' precedes '9'.
     */
    public function testUnpaddedNumbersSortByBytesNotByValue(): void
    {
        $order = new LexicographicOrder();

        self::assertLessThan(0, $order->compare('10', '9'));
        self::assertLessThan(0, $order->compare('09', '10'));
        self::assertGreaterThan(0, $order->compare('9', '10'));

        // Documented expectation: 09 < 10 < 9
        $ids = ['9', '10', '09'];
        usort($ids, fn(string $a, string $b) => $order->compare($a, $b));
        self::assertSame(['09', '10', '9'], $ids);
    }

    /**
     * Transitivity: a < b and b < c must imply a < c. The pre-1.1 comparison broke
     * this for '2' < '10' (numeric), '10' < '1a' (string), '1a' < '2' (string).
     */
    public function testOrderIsTransitiveWhereTheOldOneWasNot(): void
    {
        $order = new LexicographicOrder();
        [$a, $b, $c] = ['2', '10', '1a'];

        $sorted = [$a, $b, $c];
        usort($sorted, fn(string $x, string $y) => $order->compare($x, $y));
        self::assertSame(['10', '1a', '2'], $sorted);

        // Verify the property directly on every ordered pair.
        foreach ([[$a, $b, $c], [$c, $b, $a], [$b, $a, $c]] as [$x, $y, $z]) {
            $xy = $order->compare($x, $y) <=> 0;
            $yz = $order->compare($y, $z) <=> 0;
            if ($xy < 0 && $yz < 0) {
                self::assertLessThan(0, $order->compare($x, $z), 'transitivity violated');
            }
        }

        // The old comparison really was intransitive — pinned for contrast.
        $legacy = new NumericOrder();
        self::assertLessThan(0, $legacy->compare('2', '10'));   // numeric: 2 < 10
        self::assertLessThan(0, $legacy->compare('10', '1a'));  // string:  '10' < '1a'
        self::assertGreaterThan(0, $legacy->compare('2', '1a')); // but '2' > '1a'
    }

    /**
     * The recommended naming schemes must be unaffected by the change, otherwise
     * this would not be a safe fix for existing projects.
     */
    #[DataProvider('provideUnaffectedSchemes')]
    public function testRecommendedSchemesOrderIdenticallyUnderBothComparisons(array $ids): void
    {
        $byBytes = $ids;
        usort($byBytes, fn($a, $b) => (new LexicographicOrder())->compare($a, $b));

        $byLegacy = $ids;
        usort($byLegacy, fn($a, $b) => (new NumericOrder())->compare($a, $b));

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
    }

    // -------------------------------------------------------------------------
    // DirectoryFinder wiring
    // -------------------------------------------------------------------------

    public function testFinderUsesLexicographicOrderByDefault(): void
    {
        $this->makeFiles(['9', '10', '2']);

        self::assertSame(['10', '2', '9'], $this->listedIds());
    }

    public function testFinderOrderCanBeSwappedForTheLegacyBehaviour(): void
    {
        $this->makeFiles(['9', '10', '2']);

        self::assertSame(['2', '9', '10'], $this->listedIds(new NumericOrder()));
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

    /** A custom comparison is honoured — the ordering really is injectable. */
    public function testFinderAcceptsAnArbitraryCustomOrder(): void
    {
        $this->makeFiles(['a', 'b', 'c']);

        $reversed = new class implements OrdersMigrations {
            public function compare(string $idA, string $idB): int
            {
                return strcmp($idB, $idA);
            }
        };

        self::assertSame(['c', 'b', 'a'], $this->listedIds($reversed));
    }
}
