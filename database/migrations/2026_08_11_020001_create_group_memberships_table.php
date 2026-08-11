<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_memberships — who is in a group, and in what capacity.
 *
 * Links an existing CRM Contact (the congregant/member record; groups never
 * duplicate a person) to a Group with a `role`. Tenant-scoped by a denormalised
 * masjid_id so App\Models\Concerns\BelongsToMasjid scopes membership queries
 * directly, without a join through groups — same arrangement as
 * form_response_attachments.
 *
 * `role` is a plain string, NOT an enum: GroupMembership::ROLES is the authority,
 * validated at the request boundary. Adding a role (aide, volunteer, observer)
 * must never require `ALTER TABLE ... MODIFY` on a live table — see
 * .claude/rules/migrations.md and the `masjids.org_type` migration.
 *
 * GUARDIANSHIP IS AN EXPLICIT EDGE, NOT A LABEL. `role = guardian` alone would be
 * ambiguous the moment a classroom holds two children of the same parent: it
 * would say "this adult is a guardian somewhere in this group" without saying of
 * WHOM, so no permission check ("may this adult see this child's record?") could
 * be answered from the row. `guardian_of_contact_id` therefore names the ward,
 * and one row = one (guardian, ward, group) edge — a parent with two children in
 * one classroom holds two rows. The invariant is enforced in both directions at
 * the boundary: a guardian row MUST carry a ward, every other role MUST NOT.
 *
 * Uniqueness: the composite unique index dedupes guardian edges exactly, because
 * a guardian row always carries a non-null ward. It canNOT dedupe leader/member
 * rows, since both MySQL and SQLite treat NULLs in a unique index as distinct —
 * so duplicate non-guardian membership is rejected in GroupMembershipsController
 * before insert, and that check is the guarantee, not this index.
 *
 * Minors' data: a membership carries no content, only structure, so the
 * follow-on slices (guardian consent, private group media, retention) attach to
 * it ADDITIVELY — nullable `consent_granted_at` / `retained_until` columns or
 * their own tables. Nothing here has to be rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            // Set ONLY on a guardian row; names the member this guardian is
            // attached to WITHIN this group.
            $table->foreignId('guardian_of_contact_id')->nullable()
                ->constrained('contacts')->cascadeOnDelete();
            $table->date('joined_at')->nullable();
            $table->timestamps();

            // Exact dedupe for guardian edges (ward is never null there).
            $table->unique(['group_id', 'contact_id', 'role', 'guardian_of_contact_id'], 'group_memberships_edge_unique');

            // Roster reads ("this group's members") and person reads ("which
            // groups is this contact in"), both already tenant-filtered.
            $table->index(['masjid_id', 'group_id']);
            $table->index(['masjid_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_memberships');
    }
};
