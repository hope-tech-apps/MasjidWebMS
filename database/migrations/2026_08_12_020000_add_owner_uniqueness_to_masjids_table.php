<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S0 — ownership determinism: `masjids.user_id` becomes UNIQUE among live rows.
 *
 * Why this exists (docs/multi-tenant-admin-design.md, "Migration path"):
 * `masjids.user_id` has been nullable with NO unique index since
 * `create_masjids_table`, while `User::masjid()` is a `hasOne` — so a user owning
 * two masjids does not error, it silently resolves to ONE ARBITRARY ROW, and
 * `ResolveMasjidTenant` then binds the tenant to whichever row the optimiser
 * happened to hand back. `unique:masjids,user_id` exists in StoreMasjidRequest and
 * ProvisionMasjidRequest, but validation is not a constraint: it does not cover
 * `UpdateMasjidRequest` (fixed on this slice), console/seeder writes, or a direct
 * SQL edit. "Until ownership is deterministic, nothing downstream is safe."
 *
 * The predicate being enforced, identically on every driver:
 *
 *     UNIQUE (user_id) WHERE deleted_at IS NULL AND user_id IS NOT NULL
 *
 * Both exclusions are deliberate. Soft-deleted masjids must not pin an owner
 * (`moveToTrash` + re-provision has to keep working), and a masjid with no owner
 * is a legitimate state — production has three of them today — so NULLs must stay
 * mutually non-conflicting.
 *
 * ---------------------------------------------------------------------------
 * The two drivers express that predicate DIFFERENTLY, and cannot be made to agree
 * ---------------------------------------------------------------------------
 *
 *  - **SQLite** (the test suite, in-memory per phpunit.xml) has real partial
 *    indexes, so the predicate is written literally:
 *        CREATE UNIQUE INDEX … ON masjids (user_id) WHERE deleted_at IS NULL
 *    NULL `user_id`s never collide because SQLite treats NULLs as distinct in a
 *    UNIQUE index.
 *
 *  - **MySQL** (production runs 8.4 on DigitalOcean's managed cluster) has NO
 *    partial indexes at any version, so `WHERE …` is simply not available. The
 *    equivalent, and the same technique the design already commits to for the S2
 *    pivot's one-default-per-user rule, is a VIRTUAL generated column that
 *    collapses "not applicable" to NULL, indexed uniquely:
 *        active_owner_user_id = CASE WHEN deleted_at IS NULL THEN user_id END
 *    VIRTUAL, NOT STORED, and that distinction is load-bearing. A STORED
 *    generated column cannot be added in place: it forces ALGORITHM=COPY, a full
 *    table rebuild, and `masjids` is the parent of FIFTY-FOUR foreign keys.
 *    MySQL 8.4 refuses the rebuild with "General error: 1215 Cannot add foreign
 *    key constraint" — which is what it actually did, mid-deploy, on
 *    2026-08-11. A VIRTUAL column is a metadata-only change, and MySQL 8.0+
 *    fully supports a UNIQUE secondary index on one (verified against the
 *    production engine: duplicate live owner rejected, multiple soft-deleted
 *    and multiple unowned rows accepted). The column is computed on read, which
 *    costs nothing here because it is only ever read through the index.
 *
 *    Rows that are soft-deleted or unowned generate NULL, and MySQL also treats
 *    NULLs as distinct in a UNIQUE index — so exactly the same set of rows is
 *    constrained as under SQLite's partial index.
 *
 *    A functional key part (`UNIQUE ((CASE WHEN …))`, MySQL 8.0.13+) would fit
 *    today's server and avoid the column. The generated column is preferred
 *    anyway: it is what the design specifies for S2, it works on MySQL 5.7 and
 *    MariaDB too, and it puts the predicate in the schema where `SHOW CREATE
 *    TABLE` and a reader can both see it, instead of hiding it inside an index
 *    definition.
 *
 * The generated column is therefore a MySQL/MariaDB implementation detail and
 * does not exist under SQLite. It is listed in `Masjid::$hidden` so the raw masjid
 * payload the admin SPA reads (`MasjidsController::show`, no Resource) is byte
 * identical on both drivers. It is not fillable and nothing writes to it; the
 * database recomputes it, including when the `ON DELETE SET NULL` foreign key
 * nulls `user_id` out from under it.
 *
 * Raw SQL is unavoidable here — Blueprint can express neither a partial index nor
 * a generated column — so both branches are driver-guarded per
 * .claude/rules/migrations.md, and *both* branches do real work: this is not a
 * migration that is skipped on one driver.
 *
 * Duplicates are refused rather than silently resolved: `up()` pre-flights and
 * throws, naming `masjids:reconcile-owners`. Reconciliation is an ownership
 * decision, and a migration is the wrong place to make one unattended.
 */
return new class extends Migration
{
    /** Name of the unique index on both drivers. */
    private const INDEX = 'masjids_active_owner_unique';

    /** MySQL/MariaDB-only shadow column; see the class docblock. */
    private const COLUMN = 'active_owner_user_id';

    public function up(): void
    {
        $this->refuseToRunWithDuplicateOwners();

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE masjids ADD COLUMN ' . self::COLUMN . ' BIGINT UNSIGNED'
                . ' GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN user_id ELSE NULL END) VIRTUAL'
            );

            DB::statement('CREATE UNIQUE INDEX ' . self::INDEX . ' ON masjids (' . self::COLUMN . ')');

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::INDEX . ' ON masjids (user_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP INDEX ' . self::INDEX . ' ON masjids');
            DB::statement('ALTER TABLE masjids DROP COLUMN ' . self::COLUMN);

            return;
        }

        DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);
    }

    /**
     * Fail loudly, before any DDL, if the data would violate the new index.
     *
     * The raw constraint violation names a column and an index and leaves the
     * operator to work out that two masjids are fighting over one admin. This
     * names the users, and names the command that fixes it.
     */
    private function refuseToRunWithDuplicateOwners(): void
    {
        $duplicates = DB::table('masjids')
            ->select('user_id')
            ->whereNull('deleted_at')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id')
            ->all();

        if ($duplicates === []) {
            return;
        }

        throw new RuntimeException(
            'masjids.user_id cannot be made unique: user id(s) ' . implode(', ', $duplicates)
            . ' each own more than one live masjid. Run `php artisan masjids:reconcile-owners`'
            . ' to see the conflicts and `php artisan masjids:reconcile-owners --fix` to detach'
            . ' all but the first-created masjid of each owner, then migrate again.'
        );
    }
};
