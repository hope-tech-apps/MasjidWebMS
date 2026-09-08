<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `group_memberships.grade_label` — which grade a student is in, INSIDE a class
 * that spans more than one.
 *
 * A school does not always run one class per grade. Al-Razi's 2026 term combines
 * Pre-K with Kindergarten under one teacher, and 1st with 2nd under another: two
 * classrooms, four grades. Collapsing the classes must not collapse the grades —
 * a teacher taking a register, and an office running a roster, both still need to
 * know that Kareem is Pre-K and Jibril is KG.
 *
 * ## Why it lives on the MEMBERSHIP, not on the contact
 *
 * A grade is a property of an ENROLMENT, not of a person. The same child is 1st
 * grade in this year's membership and 2nd grade in next year's, and both rows
 * stay true — exactly the reasoning that put `consent_scope` and `provenance` on
 * this table rather than on `contacts`. Putting it on the contact would make the
 * child's grade a single mutable fact that silently rewrites last year's roster.
 *
 * ## Why a free string and not an enum
 *
 * The vocabulary is the tenant's, like every other label in this codebase
 * (.claude/rules/verticals.md): "Pre-K"/"KG"/"1st" here, "Year 1"/"Reception"
 * elsewhere, "Level 2" in a ḥalaqa. A DB enum would mean ALTER TABLE on a live
 * table the first time a school spelled it differently — the same objection
 * recorded against enums in the create_groups_table and hifz_entries docblocks.
 * Bounded at 32 characters at the request boundary, not by a constraint here.
 *
 * ## Nullable, and it stays nullable
 *
 * Only some verticals have grades at all. A masjid's ḥalaqa membership and a
 * community org's volunteer edge leave it NULL and nothing renders — the column
 * must never become required, or every non-school tenant inherits a field that
 * means nothing to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->string('grade_label', 32)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->dropColumn('grade_label');
        });
    }
};
