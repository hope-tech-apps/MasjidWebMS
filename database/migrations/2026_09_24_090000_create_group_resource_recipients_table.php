<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_resource_recipients — WHICH STUDENTS a class file was addressed to.
 *
 * `group_resources.visibility` already carried two of the three audiences a
 * teacher needs: `staff` (only the teacher) and `families` (the whole class).
 * The third — "these children and nobody else" — cannot be a column value on
 * its own, because it names a SET. This table is that set, and it exists only
 * while `visibility = 'students'`.
 *
 * ## WHY THE AUDIENCE IS A MEMBERSHIP AND NOT A CONTACT
 *
 * It points at `group_memberships`, exactly as `behavior_awards` and
 * `hifz_entries` name their subject and as a guardian edge names its ward. A
 * membership is (person, group), so a recipient row cannot name a child who is
 * not on THIS class's roster, and `App\Support\GroupAudience` resolves "may this
 * parent represent this student?" from the same edges it already uses for a
 * participant thread, an award and a ḥifẓ entry. A `contact_id` here would be a
 * second, weaker answer to a question that already has one.
 *
 * ## REMOVING A STUDENT MUST NARROW THE AUDIENCE, NEVER WIDEN IT
 *
 * `group_membership_id` CASCADES, for the reason `behavior_awards` cascades and
 * `group_threads.about_membership_id` does not: a thread is a conversation that
 * survives with a shrunken audience, but a recipient row's ENTIRE meaning is the
 * roster row it names. So taking a child off the roster removes their claim on
 * the file, and a targeted file whose last recipient has gone is readable by
 * STAFF ONLY — it does NOT fall back to the class. There is deliberately no
 * "no recipients means everyone" branch anywhere; a `students` file with an
 * empty set is the empty audience. See .claude/rules/groups.md obligation 4 and
 * DECISIONS.md, 2026-09-24.
 *
 * ## NO BACKFILL, AND THAT IS THE CAREFUL ANSWER
 *
 * Nothing here rewrites `group_resources.visibility`, and the column default
 * stays `staff`. Every existing row already records a DELIBERATE choice between
 * "only me" and "the whole class"; a backfill to `families` would publish every
 * file a teacher had marked private — the exact leak the create migration's
 * docblock says the three surviving guards exist to prevent. Existing rows keep
 * working because `students` is purely additive: no row has it, no read path
 * changes for a row that does not.
 *
 * ## Index names are explicit and short
 *
 * MySQL caps an identifier at 64 characters and Laravel's generated names for a
 * table this long overflow it (.claude/rules/migrations.md). Both are named by
 * hand and both are well inside the cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_resource_recipients', function (Blueprint $table) {
            $table->id();

            // Denormalised, as group_memberships.masjid_id is, so an audience
            // read scopes without joining back through the file or the group.
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            $table->foreignId('group_resource_id')->constrained()->cascadeOnDelete();

            // See the docblock: cascade NARROWS the audience. It must never be
            // nullOnDelete, which would leave a row naming nobody.
            $table->foreignId('group_membership_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // One student is addressed once. 44 chars.
            $table->unique(
                ['group_resource_id', 'group_membership_id'],
                'group_resource_recipients_unique'
            );

            // The family read: "which of this class's files name one of my
            // children". 41 chars.
            $table->index(
                ['masjid_id', 'group_membership_id'],
                'group_resource_recipients_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_resource_recipients');
    }
};
