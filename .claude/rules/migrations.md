# Migrations

## Raw SQL must be driver-guarded

Production runs **MySQL**. The test suite runs **in-memory SQLite** (`phpunit.xml`).
Any `DB::statement()` in a migration therefore executes against both, and the two
dialects disagree in both directions — SQLite has no `ALTER TABLE … MODIFY`, MySQL has
no `PRAGMA`.

Always guard:

```php
public function up(): void
{
    if (DB::getDriverName() !== 'mysql') {
        return;
    }

    DB::statement('ALTER TABLE notifications MODIFY message TEXT NOT NULL');
}
```

Guard `down()` the same way, or a rollback breaks in the other environment.

Prefer Blueprint methods over raw SQL wherever they exist — they already emit the right
dialect per driver. Reach for `DB::statement` only when Blueprint genuinely cannot
express it (this codebase does not install `doctrine/dbal`, so column *changes* are the
usual reason).

**Why this rule exists.** On 2026-07-24 a migration shipped an unguarded
`ALTER TABLE … MODIFY`. SQLite rejected it, so `RefreshDatabase` aborted part-way and
**every feature test errored before its first assertion** — 13 tests, 0 assertions, 13
errors in `TenantIsolationTest` alone. The tests did not fail; they stopped running, and
the cross-tenant isolation guarantee went unverified for three days. It was found by
accident on 2026-07-27.

`MigrationsBootTest::every_raw_sql_migration_is_driver_guarded` now lints for this and
names the offending file. `MigrationsBootTest` also runs the full stack on SQLite, and
CI (`.github/workflows/tests.yml`) runs it against MySQL as well, so a
dialect-specific migration cannot reach production.

## A migration that is skipped must still be correct

When you guard a migration to a single driver, be sure the *other* environment is
genuinely fine without it — do not skip merely to make CI quiet. In the case above,
skipping on SQLite is correct rather than convenient: SQLite is dynamically typed and
enforces no `VARCHAR` length, so the widening the migration performs is already true
there. Say which it is in the docblock.

## A CONDITIONAL unique index needs a different shape on each driver

"Unique, but only over the rows that still count" — unique among non-soft-deleted
rows, one default row per owner — cannot be written the same way twice:

- **SQLite** (the suite) has real partial indexes:
  `CREATE UNIQUE INDEX … ON t (col) WHERE deleted_at IS NULL`.
- **MySQL** (production is 8.4 on DigitalOcean's managed cluster) has **no
  partial indexes at any version**, so `WHERE …` is unavailable. Use a **STORED
  generated column** that collapses "not applicable" to `NULL`, with a plain
  unique index on it:
  `GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN col END) STORED`.
  MySQL treats `NULL`s as distinct in a unique index, so exactly the same rows
  end up constrained as under SQLite's partial index. A functional key part
  (`UNIQUE ((expr))`, 8.0.13+) also works on today's server, but the generated
  column keeps the predicate visible in `SHOW CREATE TABLE` and stays valid on
  MySQL 5.7 and MariaDB.

Do not reach for `unique(['col', 'deleted_at'])` as a shortcut. `NULL`s never
collide, so two live rows both sharing `deleted_at IS NULL` still pass — it
enforces nothing at all.

The generated column exists on one driver and not the other, which is a real
schema divergence: add it to the model's `$hidden` so serialized payloads stay
identical on both, and say so in the migration docblock. Worked example:
`add_owner_uniqueness_to_masjids_table`.

**Pre-flight a uniqueness migration.** Adding the index to data that already
violates it aborts `migrate` mid-deploy with a constraint error naming only a
column. Query for the duplicates in `up()` first and throw a message that names
the offending ids and the command that resolves them
(`masjids:reconcile-owners`). Choosing which duplicate survives is an operator's
decision, not a migration's.

## Portability

- Guard MySQL-only column features (collation, `MODIFY`, fulltext) with
  `DB::getDriverName() === 'mysql'` — see `create_pages_table` for the pattern.
- Prefer `foreignId(...)->constrained(...)->onDelete('cascade')`.
- Add a per-masjid unique index wherever a slug or name must be unique per tenant:
  `$table->unique(['masjid_id', 'slug'])`.
