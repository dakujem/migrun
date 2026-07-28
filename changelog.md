> 📖 back to [readme](readme.md)

# Changelog

Migrun follows semantic versioning.  
Please report any issues.


## v1.0.1

Fixes migration ordering.

**Affected: projects whose migration filenames are plain, unpadded numbers** — `1.php`, `2.php`, …
`10.php`. If that is you, read on. Everyone else is unaffected: timestamped names (the recommended
convention), zero-padded numbers, and any name containing a non-digit all behave exactly as before.

Migration IDs used to be compared as *numbers* whenever they happened to look like numbers, which
made the order unreliable — `9` and `09` were even considered the same migration. IDs are now always
compared as text, byte by byte. For plain numbers that changes the order:

```
before:   1, 2, 9, 10
now:      1, 10, 2, 9
```

### What to do

Zero-pad the numbers to a fixed width, so that text order and numeric order agree again:

```
0001_create_users.php
0002_add_email_index.php
0009_backfill_slugs.php
0010_add_orders.php
```

The ID *is* the filename stem, so renaming the files changes their IDs — the migration history has to
be updated in the same breath. Otherwise the renamed migrations look pending and re-run, while the old
IDs show up as `MISSING`:

```php
use Dakujem\Migrun\Storage\PdoStorage;

$storage = new PdoStorage($pdo);

// old ID => new ID, matching the files you just renamed
$renamed = ['1' => '0001', '2' => '0002', '9' => '0009', '10' => '0010'];

foreach ($storage->getApplied() as $entry) {
    if (isset($renamed[$entry->id()])) {
        $storage->markApplied($renamed[$entry->id()], $entry->at()); // keep the original timestamp
        $storage->markReverted($entry->id());
    }
}
```

Run it once, with the files already renamed and no pending migrations outstanding. Back up the history
first, and check with `status` that everything reads `up` afterwards.

There is deliberately no switch to restore the old comparison — it was not a valid ordering, so
keeping it would only keep the bug.


## v1.0

The initial release.


## v0.1

Early pre-release.
