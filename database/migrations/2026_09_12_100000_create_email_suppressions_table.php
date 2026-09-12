<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_suppressions — the broadcast-email opt-out list that OUTLIVES the
 * contact row (T-042c).
 *
 * This is `sms_suppressions` for the other channel, and deliberately the same
 * shape rather than a second vocabulary: the argument that made the SMS table a
 * table instead of a column is true of email word for word.
 *
 * ## Why this is a table and not a column
 *
 * A column on `contacts` cannot hold an opt-out, because the contact row is not
 * durable. `ContactsController::merge` `forceDelete()`s the absorbed record; the
 * donation importer mints and destroys placeholder contacts; a CSV re-import
 * happily creates a fresh row for a person somebody deleted last month. Every
 * one of those paths would resurrect an address that asked to be left alone as a
 * clean, mailable record — and under CAN-SPAM each subsequent commercial message
 * after a request to stop is its own statutory exposure for the organisation,
 * not a support ticket.
 *
 * So the suppression is keyed on the THING THAT PERSISTS — the normalised email
 * address — and this table has **no foreign key to `contacts` at all**. Deleting
 * a contact, merging it away, or re-importing it changes nothing here. An
 * address that unsubscribed cannot be un-suppressed by editing the directory; it
 * can only be released by the subscriber themselves, from a link sent to that
 * same mailbox (App\Http\Controllers\UnsubscribeController).
 *
 * `contacts.email_opted_out_at` (the next migration) is a DISPLAY MIRROR of this
 * table, exactly as `contacts.sms_opted_out_at` mirrors `sms_suppressions`. The
 * row here is the authority; the column is the copy the directory screen reads
 * without a join.
 *
 * ## Rows are RELEASED, never deleted
 *
 * A re-subscribe sets `released_at` and leaves the row standing. The history of
 * an opt-out is the evidence that it was honoured, and it is also what makes a
 * later re-unsubscribe an UPDATE rather than a second contradictory row. The
 * unique index over (masjid_id, email_normalized) is what guarantees that.
 *
 * ## Why the suppression is PER TENANT
 *
 * Consent is per organisation. Each masjid is its own sender identity with its
 * own reply-to, and agreeing to hear from your masjid was never agreeing to hear
 * from the school across town. A platform-wide suppression would silence
 * organisations the subscriber never asked to leave; a per-tenant one matches
 * both the unit of consent and the unit the unsubscribe link is minted for.
 *
 * `masjid_id` is NOT NULL and the model carries BelongsToMasjid
 * (.claude/rules/tenant-scoping.md). The unsubscribe landing runs UNBOUND —
 * it is a public web route with no session, exactly like the Stripe webhook —
 * so it resolves the tenant from the encrypted token in the link and writes an
 * explicit masjid_id through `withoutMasjidScope()`.
 *
 * ## What this does NOT suppress
 *
 * Broadcast email, and nothing else. Receipts, annual statements, registration
 * confirmations, contact-form replies and family sign-in codes are transactional
 * — a person asked for them by acting — and they are structurally unaffected
 * because this table is consulted in exactly ONE place,
 * `App\Services\Broadcast\BroadcastAudienceResolver::emailAudience()`. It is not
 * consulted in a Mailable, in a `MessageSending` listener, or in any global mail
 * hook, and it must never be: that would silently swallow a donor's tax receipt.
 *
 * ## Columns
 *
 * - `email_normalized` — lower-cased and trimmed
 *                        (App\Services\Broadcast\EmailSuppressionService::normalize).
 *                        Stored in one shape so the key can be matched exactly
 *                        rather than fuzzily.
 * - `reason`           — unsubscribe_link / manual / bounce. Plain string, so a
 *                        new reason never means ALTER TABLE
 *                        (.claude/rules/migrations.md).
 * - `broadcast_id`     — which message prompted it, for provenance. NULLABLE and
 *                        deliberately WITHOUT a foreign key: broadcasts are
 *                        deletable, and losing the message must never take the
 *                        opt-out with it.
 *
 * There is deliberately no name and no message body here: this table stores the
 * minimum needed to prove an opt-out was honoured.
 *
 * `email_normalized` is 191 and not 255 because it sits inside a composite
 * unique index and production MySQL is utf8mb4 — SQLite will not enforce the
 * length and MySQL will (.claude/rules/sqlite-hides-mysql-column-limits). Both
 * indexes are named BY HAND, under the 64-character MySQL identifier limit, per
 * .claude/rules/migrations.md.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Lower-cased, trimmed. Never stored in any other shape.
            $table->string('email_normalized', 191);

            // unsubscribe_link | manual | bounce
            $table->string('reason', 32)->default('unsubscribe_link');

            // The broadcast whose footer link was clicked. No FK on purpose.
            $table->unsignedBigInteger('broadcast_id')->nullable();

            $table->timestamp('suppressed_at');

            // Set when the SUBSCRIBER re-subscribes from a link sent to their own
            // mailbox. The row is NEVER deleted — a released suppression is the
            // record that the opt-out existed and was later withdrawn by the
            // person themselves.
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            // One suppression per address per tenant: a re-unsubscribe updates the
            // row rather than appending a second, contradictory verdict.
            $table->unique(['masjid_id', 'email_normalized'], 'email_suppressions_tenant_address_unique');

            // "Is this address suppressed anywhere?" — operator tooling.
            $table->index('email_normalized', 'email_suppressions_address_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
