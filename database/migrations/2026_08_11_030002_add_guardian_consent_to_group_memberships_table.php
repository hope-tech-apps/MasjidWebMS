<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guardian consent, recorded against the guardian EDGE (PLAN T-005b).
 *
 * .claude/rules/groups.md, obligation 2: "A guardian edge records a
 * relationship, NOT consent. Before any slice publishes a child's photo, name,
 * or progress to anyone, it must record consent against the guardian edge (a
 * nullable `consent_granted_at` + `consent_scope`, or its own table) and check
 * it at the point of disclosure. Absence of a record means no consent."
 *
 * These are the two nullable columns that rule anticipated, added to the row
 * that already answers "guardian of WHOM, in WHICH group" — so consent is scoped
 * to exactly one (guardian, ward, group) edge and never leaks sideways to the
 * parent's other children or their other groups.
 *
 * `consent_scope` is a plain string, NOT an enum: GroupMembership::CONSENT_SCOPES
 * is the authority and is validated at the request boundary. Adding a scope must
 * never require `ALTER TABLE ... MODIFY` on a live table — see
 * .claude/rules/migrations.md.
 *
 * PURELY ADDITIVE. Both columns are nullable with no default, so every existing
 * membership row reads as "no consent recorded", which is the correct and safe
 * interpretation: absence of a record means no consent. Nothing that exists
 * today reads these columns, so nothing that exists today changes.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed. (Note this is an
 * ADD COLUMN, which SQLite supports; the dialect trap in
 * .claude/rules/migrations.md is ALTER ... MODIFY, which this is not.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            // WHEN consent was recorded. NULL is the default state and means no
            // consent — never "unknown, assume yes".
            $table->timestamp('consent_granted_at')->nullable()->after('guardian_of_contact_id');

            // WHAT was consented to, from GroupMembership::CONSENT_SCOPES.
            // `feed` = this guardian may read the group's posts; `media` = that,
            // plus the images attached to them. A photograph of a child is the
            // sharper disclosure, so it takes its own explicit grant.
            $table->string('consent_scope', 32)->nullable()->after('consent_granted_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->dropColumn(['consent_granted_at', 'consent_scope']);
        });
    }
};
