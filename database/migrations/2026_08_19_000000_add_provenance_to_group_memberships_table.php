<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROVENANCE ON A ROSTER EDGE — how the claim arose, and who stands behind it.
 *
 * ---------------------------------------------------------------------------
 * THE DEFECT THIS ANSWERS, AND WHY THREE PREVIOUS ROUNDS DID NOT
 * ---------------------------------------------------------------------------
 *
 * A `guardian` row in `group_memberships` is the single fact the parent portal
 * reads to decide whose child's behaviour, ḥifẓ and safeguarding records a
 * credential opens. It is therefore an AUTHORIZATION GRANT. The table recorded
 * `role`, `guardian_of_contact_id` and nothing else, so no read path could tell
 *
 *     "the office established this relationship"
 *
 * from
 *
 *     "an anonymous POST to /api/v1/offerings/{slug}/register asserted it",
 *
 * and every read path trusted both equally.
 *
 * Three rounds tried to constrain WHICH ROWS the anonymous writer may touch, and
 * a reviewer found another door each time: the `registrants` array; then the
 * `payer` field, which is the same writer one field away; then
 * `RosterMergeService::carry()`, an ordinary staff de-duplication that re-points
 * a manufactured edge onto the real child. Enumerating doors is the wrong axis.
 * Recording the AUTHORITY is the right one, because it is a property of the row
 * rather than of the path that happened to write it.
 *
 * ---------------------------------------------------------------------------
 * THE MODEL
 * ---------------------------------------------------------------------------
 *
 *   - `confirmed`     — an authenticated staff act stands behind this row.
 *                       `confirmed_by_user_id` names the person and
 *                       `confirmed_at` says when. It grants exactly what a
 *                       membership granted before this migration.
 *   - `self_asserted` — nobody authenticated ever said this was true. It is a
 *                       public form's claim about a person. It may appear on a
 *                       roster, it may drive capacity and attendance, and a
 *                       teacher may keep records about the child it enrols — but
 *                       it gives its HOLDER no standing anywhere: no feed, no
 *                       thread, no award, no ḥifẓ record, and no eligibility for
 *                       a family credential.
 *
 * The values are plain strings validated against `GroupMembership::PROVENANCES`,
 * NOT a DB enum — the same reasoning as `role` on this table and
 * `Masjid::ORG_TYPES`: adding a third provenance must never mean
 * `ALTER TABLE … MODIFY` on a live table. See .claude/rules/migrations.md.
 *
 * ---------------------------------------------------------------------------
 * WHY THE DEFAULT IS `confirmed`, WHICH LOOKS LIKE THE UNSAFE DIRECTION
 * ---------------------------------------------------------------------------
 *
 * Because of what the existing rows ARE, measured rather than assumed:
 * production carries **0 `group_memberships` across all 4 masjids** — this code
 * is deployed and has never been used. Every row this migration can meet in any
 * environment was therefore written by a seeder, a fixture, or
 * `GroupMembershipsController` behind `auth:sanctum` + `admin` + `tenant` +
 * `permission:manage contacts`. Backfilling those as `self_asserted` would open
 * an empty pending queue and refuse a credential to guardians a staff member
 * personally typed in — an over-refusal with no defect behind it.
 *
 * The fail-closed property is bought at the WRITER instead, which is where it
 * can be true rather than merely defaulted: `RegistrationService::writeRoster
 * Memberships()` — the ONE unauthenticated writer in this application — sets
 * `self_asserted` explicitly on every row it creates, and never confirms,
 * regardless of who triggered the confirmation (a free registration confirms
 * in-request; a paid one from a Stripe webhook; an admin may re-run neither).
 * The registrant list came from a public form either way, so the authority is
 * the same either way. An ambient "is there a logged-in user right now" hook
 * would get that wrong the moment a staff member re-drives the same path, and
 * this file's own model already records what happens when a hook reasons about
 * ambient state (`GroupMembership::booted()`).
 *
 * A value this application does not recognise reads as NOT confirmed
 * (`GroupMembership::isConfirmed()`), so a typo, a future third state, or a hand
 * edit fails closed at every read even though the column default does not.
 *
 * ---------------------------------------------------------------------------
 * `source_registration_id` — the OTHER half of provenance
 * ---------------------------------------------------------------------------
 *
 * "Who stands behind it" needs a companion: "how did it arise". Without it the
 * office is shown a pending claim with no way to judge it — a name and nothing
 * else. This FK answers "which signup asserted this", which carries the payer
 * who typed it and the program they typed it into, so the confirm screen can say
 * *Bilal Attacker, registering for After School Club* rather than *pending*.
 *
 * `nullOnDelete`, deliberately: a cancelled or purged registration must not take
 * a roster row with it (a child who attended is still a child who attended), and
 * losing the detail does not lose the decision — `provenance` stays
 * `self_asserted` on its own.
 *
 * Nothing is added to the unique index. `group_memberships_edge_unique`
 * (group, contact, role, ward) must keep meaning "one edge per pair": a
 * confirmed edge and a self-asserted one over the same pair are the SAME edge in
 * two states, not two edges, and letting them coexist would give one pair two
 * answers to the only question this table exists to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->string('provenance', 32)
                ->default('confirmed')
                ->after('guardian_of_contact_id');

            $table->timestamp('confirmed_at')->nullable()->after('provenance');

            // The staff member who stands behind the row. nullOnDelete: an
            // administrator leaving the organisation must not delete the
            // rosters they built, and `provenance` carries the decision on its
            // own — this column carries WHO, which is a detail of it.
            $table->foreignId('confirmed_by_user_id')->nullable()
                ->after('confirmed_at')
                ->constrained('users')->nullOnDelete();

            $table->foreignId('source_registration_id')->nullable()
                ->after('confirmed_by_user_id')
                ->constrained('registrations')->nullOnDelete();

            // The office's queue: "the pending claims in this group". Leading
            // with masjid_id keeps it usable by the tenant-scoped reads that are
            // the only ones this application makes.
            $table->index(['masjid_id', 'group_id', 'provenance'], 'group_memberships_provenance_idx');
        });
    }

    public function down(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->dropIndex('group_memberships_provenance_idx');
            $table->dropConstrainedForeignId('source_registration_id');
            $table->dropConstrainedForeignId('confirmed_by_user_id');
            $table->dropColumn(['provenance', 'confirmed_at']);
        });
    }
};
