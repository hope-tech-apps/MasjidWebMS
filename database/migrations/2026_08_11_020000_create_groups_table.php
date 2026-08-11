<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * groups — the second scoping level of the Manara core: org -> group -> member
 * (DECISIONS.md, 2026-08-10). One table serves every vertical: classrooms for a
 * School, halaqat / weekend-school circles for a Masjid, volunteer teams for a
 * Community org. A group belongs to exactly ONE organization.
 *
 * Tenant-scoped by masjid_id. MySQL has no row-level security, so isolation is
 * enforced in the app layer by App\Models\Concerns\BelongsToMasjid and proved by
 * tests/Feature/GroupTenantIsolationTest.
 *
 * `kind` is a plain string, NOT an enum — the same reasoning as `masjids.org_type`
 * (see that migration's docblock): adding a group kind must never require
 * `ALTER TABLE ... MODIFY` on a live table, which .claude/rules/migrations.md
 * records silently aborting the SQLite test run for three days. The allowed set
 * lives in PHP as Group::KINDS and is validated at the request boundary.
 *
 * Minors' data — these rosters will hold children (DECISIONS.md 2026-08-10). The
 * table is deliberately shaped so the follow-on slices are ADDITIVE:
 *   - private media becomes its own table (a `group_media` row referencing
 *     group_id + masjid_id, bytes on the private disk per
 *     .claude/rules/private-uploads.md) — no column here has to change;
 *   - guardian consent becomes its own table or nullable columns on the
 *     membership, never a rewrite of `kind` or `role`;
 *   - retention becomes a nullable `retained_until` / a scheduled purge over
 *     soft-deleted rows — which is why the table soft-deletes from day one
 *     rather than losing a roster the moment an admin mis-clicks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Stable, URL-safe handle. Unique PER MASJID (see the index below):
            // two tenants may both run a "grade-3" without colliding.
            $table->string('slug');
            $table->string('kind', 32)->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            // Session/term window. Generic across all three verticals (a school
            // term, a Ramadan halaqa, a volunteer campaign) and nullable, so a
            // standing group simply leaves both null.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Per-tenant slug uniqueness, per .claude/rules/migrations.md.
            // NOTE it spans soft-deleted rows too: a deleted group keeps its slug
            // because its roster is retained (minors' records), so recreating the
            // same handle is refused at the request boundary with a clean 422
            // rather than blowing up on the index.
            $table->unique(['masjid_id', 'slug']);

            // Every tenant-scoped read is WHERE masjid_id = ? and then filters or
            // looks up by id — mirrors the contacts table.
            $table->index(['masjid_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
