<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organisation can belong to another organisation.
 *
 * The shape the platform was already growing into: Manara → MEC → the services
 * that have outgrown being a paragraph (a school, a clinic, an academy). A
 * `services` row is CONTENT — title, summary, description, media, hanging off
 * one masjid — and cannot back a context the app reloads into. A service that
 * earns its own project graduates to a `masjids` row, and this column is what
 * remembers where it came from.
 *
 * `org_type` (2026-08-11) already says WHAT a tenant is. This says WHOSE it is.
 * The two are independent: a child can be a school under a masjid, or a clinic
 * under a community org.
 *
 * ---------------------------------------------------------------------------
 * NO DATABASE FOREIGN KEY — deliberately, and this is not laziness
 * ---------------------------------------------------------------------------
 * SQLite cannot ALTER TABLE ADD a foreign key, so Laravel rebuilds the whole
 * table to add one. `masjids.user_id` is protected by a PARTIAL unique index
 * (`UNIQUE (user_id) WHERE deleted_at IS NULL AND user_id IS NOT NULL`, added
 * 2026_08_12_020000 as raw SQL), and the rebuild does not carry that WHERE
 * clause across — the index comes back as a plain UNIQUE and starts rejecting
 * rows the application is supposed to allow. Measured: adding `->constrained()`
 * here broke 7 tests across masjid ownership, Stripe accounts, the public
 * directory and the tenancy canary, none of which are about hierarchy.
 *
 * The suite runs SQLite and production runs MySQL, so a driver-guarded FK would
 * mean the tested behaviour is not the deployed behaviour — the exact trap
 * .claude/rules/migrations.md exists to prevent. So: a plain indexed column,
 * with the delete behaviour enforced in `Masjid::booted()` where it is
 * identical on both drivers and can actually be tested.
 *
 * ---------------------------------------------------------------------------
 * Orphan, NEVER cascade
 * ---------------------------------------------------------------------------
 * A cascade here would mean deleting MEC also deletes IntelliCor — its roster,
 * its donations, its media, every row scoped to it. Whatever removing a parent
 * ought to mean, it can never mean silently destroying an entire other
 * organisation's data. Orphaned children become top-level orgs, which is
 * recoverable by setting the column again; the alternative is not recoverable
 * at all.
 *
 * ---------------------------------------------------------------------------
 * This column grants NOTHING
 * ---------------------------------------------------------------------------
 * It is a directory edge, not an authorization one. Being a child does not let
 * a parent's admin read the child's contacts, donations or rosters — every
 * query in this application is still scoped by `masjid_id` through
 * BelongsToMasjid (.claude/rules/tenant-scoping.md), and nothing here changes
 * that. If cross-org reading is ever wanted it needs its own decision, its own
 * middleware and its own tests. Do not quietly start joining on this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            // A plain column, not foreignId()->constrained() — see above.
            $table->unsignedBigInteger('parent_id')->nullable()->after('org_type');

            // "Everything under MEC" — the app's switcher query, and the admin
            // list's grouping.
            $table->index('parent_id', 'masjids_parent_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->dropIndex('masjids_parent_id_index');
            $table->dropColumn('parent_id');
        });
    }
};
