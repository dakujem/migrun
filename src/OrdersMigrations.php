<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * Defines the order of migrations by comparing their IDs.
 *
 * Migration order determines everything: which pending migration runs first, which
 * applied one is rolled back first, and how status is listed. All of it is derived
 * from this single comparison, so that every part of the library agrees.
 *
 * Implementations MUST provide a total order:
 *   - antisymmetric — compare($a, $b) and compare($b, $a) have opposite signs,
 *   - transitive    — if $a < $b and $b < $c then $a < $c,
 *   - and return 0 only for identical IDs.
 *
 * A comparison that returns 0 for two different IDs, or that is not transitive,
 * makes the resulting sort order undefined.
 *
 * @see LexicographicOrder The default — byte-by-byte comparison.
 * @see NumericOrder       Legacy pre-1.1 behaviour; violates the rules above.
 */
interface OrdersMigrations
{
    /**
     * Compare two migration IDs, usort-style.
     *
     * @return int Negative if $idA sorts before $idB, positive if after, 0 if equal.
     */
    public function compare(string $idA, string $idB): int;
}
