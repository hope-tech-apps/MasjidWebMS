<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Answered state on a contact-us message (PLAN T-042d).
 *
 * Until now a contact-us message had exactly two states — it exists, or an
 * admin deleted it. There was no way to tell a message somebody had already
 * dealt with from one nobody had opened, so the observed failure was two
 * members of staff writing back to the same person, and a message sitting
 * unanswered for a week because each reader assumed the other had it.
 *
 * Three columns, and the third is the one that is easy to talk yourself out of:
 *
 *  - `answered_at` — the fact. Nullable, because "not answered" is the normal
 *    state and must not be represented by a sentinel date.
 *  - `answered_by_user_id` — who, as a live link, so the admin screen can show
 *    an avatar and so a report can group by staff member. `nullOnDelete` for the
 *    same reason appointment_request_notes.user_id is nullable: the triage state
 *    is the ORGANISATION's record, and deleting a staff account must not destroy
 *    it or, worse, cascade the message away.
 *  - `answered_by_name` — the SNAPSHOT, which is why the FK alone is not enough.
 *    Once the FK nulls, "answered by whom?" becomes unanswerable, and the office
 *    is back to the problem this migration exists to fix. The same argument
 *    .claude/rules/auth-permissions.md makes for contact_login_events: an audit
 *    fact must survive the actor.
 *
 * No `masjid_id` is added. contact_us_messages is scoped through
 * contacter -> mobileAppUser -> masjid_id by hand in every query that touches
 * it (see ContactRequestsController and both public intake controllers).
 * Adding the column would make TenantScopingCoverageTest layer 4 demand
 * BelongsToMasjid on the model, whose global scope would silently rewrite those
 * three hand-written queries — including the UNAUTHENTICATED intake paths,
 * which never bind a tenant. See .claude/rules/tenant-scoping.md.
 *
 * Blueprint only, so no driver guard is needed (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_us_messages', function (Blueprint $table) {
            $table->timestamp('answered_at')->nullable()->after('message');

            $table->foreignId('answered_by_user_id')
                ->nullable()
                ->after('answered_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('answered_by_name')->nullable()->after('answered_by_user_id');

            // The admin inbox's only new read is "which of these are still
            // waiting", and the list is already ordered by created_at. Named by
            // hand and kept well under MySQL's 64-character identifier limit —
            // the generated name for a two-column index on this table would be
            // fine, but naming it makes the pair deliberate rather than
            // incidental (.claude/rules/migrations.md).
            $table->index(['answered_at', 'created_at'], 'contact_us_msgs_answered_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_us_messages', function (Blueprint $table) {
            $table->dropIndex('contact_us_msgs_answered_created_idx');
            $table->dropConstrainedForeignId('answered_by_user_id');
            $table->dropColumn(['answered_at', 'answered_by_name']);
        });
    }
};
