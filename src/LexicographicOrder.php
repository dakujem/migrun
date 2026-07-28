<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * Orders migrations by comparing their IDs byte by byte — the default.
 *
 * This is plain `strcmp`: the same rule as `LC_ALL=C sort` or git's tree ordering.
 * It is deterministic, locale-independent, identical on every platform, and it is a
 * true total order, which is what the rest of the library relies on.
 *
 * Numbers are NOT interpreted as values. Byte-wise, '10' sorts before '9', because
 * '1' precedes '9'. Zero-pad numeric IDs to a fixed width so that byte order and
 * numeric order coincide:
 *
 *   001_create_users.php   NOT   1_create_users.php
 *   010_add_index.php            10_add_index.php
 *
 * The recommended YYYYMMDD_HHMMSS_ prefix already satisfies this.
 *
 * @see OrdersMigrations
 */
final readonly class LexicographicOrder implements OrdersMigrations
{
    public function compare(string $idA, string $idB): int
    {
        return strcmp($idA, $idB);
    }
}
