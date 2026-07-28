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

## Portability

- Guard MySQL-only column features (collation, `MODIFY`, fulltext) with
  `DB::getDriverName() === 'mysql'` — see `create_pages_table` for the pattern.
- Prefer `foreignId(...)->constrained(...)->onDelete('cascade')`.
- Add a per-masjid unique index wherever a slug or name must be unique per tenant:
  `$table->unique(['masjid_id', 'slug'])`.
