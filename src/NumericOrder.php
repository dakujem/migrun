<?php

declare(strict_types=1);

namespace Dakujem\Migrun;

/**
 * Legacy ordering: PHP's `<=>` on the raw ID strings, as used before v1.1.
 *
 * Provided ONLY so that a project which relied on the pre-1.1 ordering can keep it
 * without renaming its migration files. New projects should use the default
 * LexicographicOrder and zero-pad numeric IDs instead.
 *
 * Be aware of what this ordering actually does, because it is NOT a valid total
 * order — the rest of the library assumes one, so sorting results may be undefined:
 *
 *   - Distinct IDs can compare EQUAL, because PHP compares two numeric strings as
 *     numbers: '9' <=> '09' is 0, '10' <=> '010' is 0, and '100' <=> '1e2' is 0.
 *
 *   - It is NOT transitive once numeric and non-numeric IDs are mixed. With
 *     '2', '10' and '1a': '2' < '10' (numeric), '10' < '1a' (string), yet
 *     '1a' < '2' (string). PHP's sort functions give undefined results for such a
 *     comparison.
 *
 * It is safe in practice only if every ID is numeric and no two IDs share a numeric
 * value — e.g. an unpadded 1, 2, 3 … 10, 11 sequence with no other naming in play.
 *
 * @see LexicographicOrder The default, and the recommended choice.
 * @see OrdersMigrations
 */
final readonly class NumericOrder implements OrdersMigrations
{
    public function compare(string $idA, string $idB): int
    {
        return $idA <=> $idB;
    }
}
