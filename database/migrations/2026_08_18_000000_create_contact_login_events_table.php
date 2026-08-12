<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-015d(admin) — the record of WHO opened, re-addressed or closed a family
 * sign-in, and when.
 *
 * ---------------------------------------------------------------------------
 * Why the four `login_*` columns are not the audit trail
 * ---------------------------------------------------------------------------
 *
 * `contacts.login_enabled_at` says a login is on. It cannot say who turned it
 * on, from which address it was turned on before today, or that it was revoked
 * in March and re-opened in April by somebody else. Those columns are STATE, and
 * state is overwritten: the act that produced it leaves no trace at all.
 *
 * That gap matters more here than almost anywhere else in this application,
 * because what is being granted is a stranger's view of a specific child's
 * photographs, behaviour records and safeguarding conversations. "It was on" is
 * not an answer to "who gave this person access to my daughter's file?" — and
 * that question is asked precisely in the circumstances where the state columns
 * have already been overwritten by whoever was covering their tracks.
 *
 * So every act is appended here, and nothing in the application updates or
 * deletes a row (App\Models\ContactLoginEvent enforces that at the model layer).
 *
 * ---------------------------------------------------------------------------
 * The row SNAPSHOTS what it names, and both foreign keys are nullable
 * ---------------------------------------------------------------------------
 *
 * `contact_login_codes` cascades on both its parents, because a code is
 * ephemeral and prunable. An audit row is the opposite kind of object, and the
 * two ways it would otherwise be destroyed are ordinary operations:
 *
 *  - `ContactsController::merge` FORCE-deletes the absorbed contact. A cascade
 *    would erase the grant history of exactly the row an operator just decided
 *    was a duplicate.
 *  - a staff member who leaves is deleted from `users`. A cascade would erase
 *    every grant they ever made, which is the opposite of what an audit exists
 *    for; and even `nullOnDelete` alone would leave "somebody, at 14:02".
 *
 * Hence `nullOnDelete` on both, PLUS a snapshot of the identifying facts on the
 * row itself (`login_email`, `actor_name`, `actor_email`).
 *
 * The snapshot is doing MORE work than the foreign key, not less, and this is
 * measured rather than assumed: `App\Models\User` uses SoftDeletes, so the
 * ordinary "this person left" is an UPDATE and `nullOnDelete` never fires at
 * all. The key stays intact and the RELATION goes dark instead — the
 * SoftDeletes scope resolves `actor()` to null — so a panel that read the name
 * through the key would print nothing for exactly the staff member an audit is
 * most often asked about. `nullOnDelete` is the backstop for the hard purge;
 * `actor_name`/`actor_email` are what answer the question in both cases. Both
 * paths are asserted in
 * FamilyLoginEnablementTest::the_audit_trail_survives_the_staff_member_who_wrote_it.
 *
 * The row keeps saying what happened after either side of it is gone. `masjid_id` is the exception
 * and stays `cascadeOnDelete`: it is the tenant key rather than a subject, it
 * must be NOT NULL for the `BelongsToMasjid` global scope to mean anything
 * (.claude/rules/tenant-scoping.md), and `masjids` soft-deletes in practice.
 *
 * `action` is a plain string, not an enum: adding a verb must never be
 * `ALTER TABLE … MODIFY` on a live table (.claude/rules/migrations.md). See
 * App\Models\ContactLoginEvent::ACTIONS for the values written today.
 *
 * Blueprint only — no raw SQL, so there is nothing to driver-guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_login_events', function (Blueprint $table) {
            $table->id();

            // Denormalised tenant key, exactly like contact_login_codes: an
            // audit row must be scopable without joining through contacts,
            // because BelongsToMasjid's global scope is the only isolation this
            // application has — and after a merge there may be no contact left
            // to join to.
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // WHOSE access. Nullable + nullOnDelete: see the docblock.
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            // 'enabled' | 'revoked'. See ContactLoginEvent::ACTIONS.
            $table->string('action', 32);

            // The credential address AS OF THIS ACT. Not a duplicate of
            // `contacts.login_email` — that column holds only the current value,
            // so without this snapshot a re-addressing ("we moved her login to
            // the father's mailbox") is invisible in the history it produced.
            $table->string('login_email')->nullable();

            // WHO did it. Nullable for both a departed staff member (nullOnDelete)
            // and for a console/system caller that has no authenticated user.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // …and who that WAS, frozen. A name read back through the foreign key
            // is the name the person has today, which is the wrong answer to an
            // audit question, and no answer at all once the row is gone.
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();

            // 45 chars = the longest IPv6 form, matching contact_login_codes.
            $table->string('actor_ip', 45)->nullable();

            $table->timestamps();

            // The history panel: this tenant's events for one contact, newest
            // first. `id` rather than `created_at` as the tiebreaker because two
            // acts in the same second must still have a defined order.
            $table->index(['masjid_id', 'contact_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_login_events');
    }
};
