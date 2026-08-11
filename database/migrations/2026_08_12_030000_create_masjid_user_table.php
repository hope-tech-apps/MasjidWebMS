<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S2 — the `masjid_user` membership pivot (docs/multi-tenant-admin-design.md).
 *
 * One human can be an admin of more than one organisation — a consultant running
 * two masjids, a parent who is staff at the school and a member at the masjid.
 * Today that is inexpressible: authorization is read off `masjids.user_id` through
 * `User::masjid()`, a `hasOne`, so a user has at most one tenant and the tenant is
 * whichever row the database hands back. S0 made that pointer deterministic; this
 * slice adds the table that will eventually make it irrelevant for AUTHORIZATION.
 *
 * `masjids.user_id` STAYS as the ownership/billing pointer and stays authoritative
 * for tenant resolution. **Nothing reads this table yet.** S2 is a pure schema +
 * data slice: the resolver, `setFromMembership()` and the fail-closed middleware
 * are S3. Adding the rows first, in their own deployable step, is what lets S3
 * flip the read over with the data already verified in production.
 *
 * ---------------------------------------------------------------------------
 * The schema, and why each part of it exists
 * ---------------------------------------------------------------------------
 *
 *   masjid_user: id, masjid_id FK→masjids, user_id FK→users, role varchar(64),
 *                is_default bool default 0, timestamps
 *     unique (masjid_id, user_id)      -- one membership per person per tenant
 *     unique (user_id, default_key)    -- MySQL: default_key = is_default ? user_id : NULL
 *     index  (user_id)                 -- memberships() is always looked up by user
 *
 * `role` is a plain STRING, not an enum and not spatie's `teams` feature. It
 * mirrors the existing bridged role names in `User::TYPE_ROLE_MAP`
 * (`super-admin` / `masjid-admin` / `member`) and ships ADVISORY ONLY —
 * authorization stays on the global `users.type` bridge, so `Permission::count()`
 * and the whole role×permission matrix are untouched by this slice. Turning
 * spatie `teams => true` on would ALTER `model_has_roles`' primary key and is not
 * reversible alongside this work; the design defers it to its own initiative.
 * A varchar also means adding a per-tenant role later never needs an
 * `ALTER TABLE … MODIFY` on a live table (see .claude/rules/migrations.md).
 *
 * Both foreign keys cascade on delete. A membership is an authorization grant: if
 * the user or the organisation is really gone, the grant must go with it rather
 * than linger pointing at a dead id that a later row could reuse. Note this is a
 * HARD delete only — both models are soft-deleting, and `moveToTrash` therefore
 * leaves memberships intact, which is deliberate: a restored masjid comes back
 * with its admins. `restrictOnDelete` was rejected for exactly the reason the
 * design's critique gives — it breaks trashing.
 *
 * ---------------------------------------------------------------------------
 * "Exactly one DEFAULT membership per user" is a DATABASE fact, not a convention
 * ---------------------------------------------------------------------------
 *
 * `is_default` is what a multi-tenant admin's session falls back to, and it is the
 * only reason the S3 resolver can keep binding a single tenant for the admins who
 * have exactly one. If two rows could carry `is_default = 1`, the fallback would
 * pick an arbitrary one — which is the very non-determinism S0 just spent a slice
 * removing from `masjids.user_id`. Enforcing it in application code only would
 * leave it open to a seeder, an artisan command or a hand-written UPDATE, the same
 * three holes that made validation-only ownership uniqueness worthless.
 *
 * The predicate, identically on every driver:
 *
 *     UNIQUE (user_id) WHERE is_default = 1
 *
 * Rows with `is_default = 0` are unconstrained on purpose — holding memberships in
 * five masjids is the entire point of the table.
 *
 * The two drivers spell that predicate differently and cannot be made to agree:
 *
 *  - **SQLite** (the suite, in-memory per phpunit.xml) has real partial indexes,
 *    so the predicate is written literally.
 *  - **MySQL** (production, 8.4 on DigitalOcean's managed cluster) has NO partial
 *    indexes at any version. The equivalent — and the technique the design names
 *    for this table, already proven by S0's `active_owner_user_id` — is a STORED
 *    generated column that collapses "not a default" to NULL:
 *        default_key = CASE WHEN is_default = 1 THEN user_id ELSE NULL END
 *    MySQL treats NULLs as distinct in a unique index, so non-default rows never
 *    collide and exactly the same set of rows ends up constrained as under
 *    SQLite's partial index. The column is STORED (not VIRTUAL) because MySQL
 *    cannot index a VIRTUAL column in a UNIQUE index, and it recomputes itself on
 *    UPDATE — so moving a user's default from masjid A to masjid B is an ordinary
 *    two-row update, not a constraint violation, provided the old default is
 *    cleared in the same transaction.
 *    The unique index is on `(user_id, default_key)` as the design writes it;
 *    `default_key` alone would be equivalent, but leading with `user_id` keeps the
 *    index usable for the plain by-user lookups the resolver will make.
 *
 * `default_key` therefore exists on MySQL/MariaDB and NOT on SQLite — a real
 * schema divergence, handled the same way S0 handled its twin: it is listed in
 * `MasjidUser::$hidden` so a serialized membership is byte-identical on both
 * drivers, it is not fillable, and nothing ever writes to it.
 *
 * Raw SQL is unavoidable here (Blueprint expresses neither a partial index nor a
 * generated column), so both branches are driver-guarded per
 * .claude/rules/migrations.md — and both branches do real work; this is not a
 * migration that is skipped on one driver.
 *
 * ---------------------------------------------------------------------------
 * Backfill: every current admin lands with exactly ONE default membership
 * ---------------------------------------------------------------------------
 *
 *     INSERT … SELECT id, user_id, 'masjid-admin', 1
 *     FROM masjids WHERE user_id IS NOT NULL AND deleted_at IS NULL
 *
 * This is the whole point of doing the table and the data in one migration: the
 * S3 resolver must reproduce TODAY's behaviour exactly, and it can only do that if
 * every admin who resolves to a masjid today has precisely one default membership
 * naming that same masjid the moment S3 ships.
 *
 *  - **SuperAdmins get no rows.** They are not bound by ownership today (the
 *    middleware binds them from the route, or leaves them unbound), so giving them
 *    memberships would invent access that does not exist.
 *  - **Unowned masjids get no row** — production carries several; NULL is "no
 *    owner", not a user.
 *  - **Soft-deleted masjids get no row.** A trashed masjid does not grant access
 *    today and must not start granting it here. This also matches the predicate of
 *    S0's ownership index, so the two definitions of "live ownership" cannot drift.
 *  - A masjid owned by a SOFT-DELETED user does get a row: that user still owns it
 *    today. Parity with current behaviour is the bar, not tidiness.
 *
 * The insert cannot violate the one-default rule, and that is a consequence of S0
 * rather than luck: `masjids.user_id` is already unique among live rows, so each
 * user id can appear at most once in the SELECT. If a database somehow reached
 * this migration without that guarantee, the unique index — created BEFORE the
 * backfill, deliberately — aborts the migration loudly instead of silently
 * seeding two defaults.
 *
 * `down()` drops the table, which takes the generated column, both unique indexes
 * and every backfilled row with it on either driver — so it needs no driver branch
 * of its own. The rollback is genuinely clean because nothing outside this slice
 * reads the table and the spatie `teams` flip stayed out of scope.
 */
return new class extends Migration
{
    private const TABLE = 'masjid_user';

    /** Name of the one-default-per-user unique index on both drivers. */
    private const DEFAULT_INDEX = 'masjid_user_default_unique';

    /** MySQL/MariaDB-only shadow column carrying that index; see the docblock. */
    private const DEFAULT_KEY = 'default_key';

    /**
     * The role every backfilled owner receives.
     *
     * Deliberately a literal rather than `User::TYPE_ROLE_MAP['MasjidAdmin']`: a
     * migration is a historical record and must keep producing the same rows even
     * if the model's map is edited later. `MasjidMembershipPivotTest` asserts the
     * two still agree, so a rename cannot drift silently.
     */
    private const OWNER_ROLE = 'masjid-admin';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id');
            $table->foreignId('user_id');

            // Advisory in this slice; mirrors User::TYPE_ROLE_MAP's values.
            $table->string('role', 64);

            // The membership a multi-tenant admin falls back to. Constrained to
            // one per user below — the constraint is the reason this column is
            // trustworthy.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            // One membership row per person per tenant; a second grant for the
            // same pair is a bug, not an upgrade.
            $table->unique(['masjid_id', 'user_id']);

            // Memberships are always read "for this user" — the resolver's only
            // access pattern.
            $table->index('user_id');

            // The keys are declared BEFORE the foreign keys deliberately, which is
            // also why this is not the `foreignId(...)->constrained()` shorthand
            // .claude/rules/migrations.md otherwise prefers: Blueprint emits its
            // commands in declaration order, and InnoDB auto-creates an index for
            // any FK column that does not already have one. With `constrained()`
            // the FKs are emitted first, so MySQL would build its own index on
            // `user_id` and the declared one above would land on top of it as an
            // exact duplicate. Declared in this order, InnoDB reuses the unique
            // (`masjid_id` is its leftmost part) and the `user_id` index instead.
            $table->foreign('masjid_id')->references('id')->on('masjids')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $this->addOneDefaultPerUserIndex();
        $this->backfillMembershipsFromOwnership();
    }

    public function down(): void
    {
        // Drops the indexes and the MySQL-only generated column along with the
        // table, on both drivers — no driver branch required.
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * UNIQUE (user_id) WHERE is_default = 1, spelled per driver.
     *
     * Created BEFORE the backfill on purpose: if the data being migrated could
     * ever produce two defaults for one user, this must abort the migration
     * rather than let the rows in and leave the constraint unenforceable.
     */
    private function addOneDefaultPerUserIndex(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE ' . self::TABLE . ' ADD COLUMN ' . self::DEFAULT_KEY . ' BIGINT UNSIGNED'
                . ' GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN user_id ELSE NULL END) STORED'
            );

            DB::statement(
                'CREATE UNIQUE INDEX ' . self::DEFAULT_INDEX
                . ' ON ' . self::TABLE . ' (user_id, ' . self::DEFAULT_KEY . ')'
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::DEFAULT_INDEX
            . ' ON ' . self::TABLE . ' (user_id) WHERE is_default = 1'
        );
    }

    /**
     * Give every CURRENT admin the one default membership that reproduces the
     * masjid they resolve to today. See the class docblock for what is excluded
     * and why.
     *
     * Written through the query builder rather than raw SQL so the same statement
     * compiles correctly on both drivers (`NOW()` vs `CURRENT_TIMESTAMP` and
     * quoting are handled for us), and so it stays a single set-based INSERT …
     * SELECT — a row-by-row PHP loop over production's masjids would be both
     * slower and non-atomic.
     */
    private function backfillMembershipsFromOwnership(): void
    {
        $now = now()->toDateTimeString();

        DB::table(self::TABLE)->insertUsing(
            ['masjid_id', 'user_id', 'role', 'is_default', 'created_at', 'updated_at'],
            DB::table('masjids')
                ->selectRaw('id, user_id, ?, 1, ?, ?', [self::OWNER_ROLE, $now, $now])
                ->whereNotNull('user_id')
                ->whereNull('deleted_at')
        );
    }
};
