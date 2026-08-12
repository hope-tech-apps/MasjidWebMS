<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sms_suppressions — the opt-out list that OUTLIVES the contact row (T-009).
 *
 * ## Why this is a table and not a column
 *
 * A column on `contacts` cannot hold an opt-out, because the contact row is not
 * durable. `ContactsController::merge` `forceDelete()`s the absorbed record;
 * the donation importer mints and destroys placeholder contacts; a CSV
 * re-import happily creates a fresh row for a person somebody deleted last
 * month. Every one of those paths would resurrect a number that said STOP as a
 * clean, messageable record — and under the TCPA each subsequent message is its
 * own statutory exposure for the organisation, not a support ticket.
 *
 * So the suppression is keyed on the THING THAT PERSISTS — the phone number in
 * E.164 — and this table has **no foreign key to `contacts` at all**. Deleting
 * a contact, merging it away, or re-importing it changes nothing here. A number
 * that said STOP cannot be un-suppressed by editing the directory; it can only
 * be released by the subscriber themselves texting START back to the same
 * number (App\Http\Controllers\SmsWebhookController).
 *
 * ## Rows are RELEASED, never deleted
 *
 * A START sets `released_at` and leaves the row standing. The history of an
 * opt-out is the evidence that it was honoured, and it is also what makes a
 * later re-STOP an UPDATE rather than a second contradictory row. The unique
 * index over (masjid_id, phone_e164) is what guarantees that.
 *
 * ## Why the suppression is PER TENANT
 *
 * Consent is per organisation: agreeing to hear from your masjid is not
 * agreeing to hear from the school across town, and each tenant sends from its
 * own registered 10DLC number (masjid_sms_senders). STOP is a reply to ONE
 * number, and carriers scope it exactly that way. A platform-wide suppression
 * would silence organisations the subscriber never contacted; a per-tenant one
 * matches both the law's unit of consent and the carrier's unit of enforcement.
 *
 * `masjid_id` is NOT NULL and the model carries BelongsToMasjid
 * (.claude/rules/tenant-scoping.md). The inbound webhook runs UNBOUND, exactly
 * like the Stripe webhook, so it resolves the tenant from the number the
 * message was sent TO and writes an explicit masjid_id.
 *
 * ## Columns
 *
 * - `phone_e164`   — the normalised number (App\Services\Sms\PhoneNumber). A
 *                    number that cannot be normalised with confidence is never
 *                    messaged in the first place, precisely so this key can be
 *                    matched exactly rather than fuzzily.
 * - `reason`       — stop_keyword / manual / provider. Plain string, so a new
 *                    reason never means ALTER TABLE (.claude/rules/migrations.md).
 * - `keyword`      — the exact word the subscriber sent (STOP, CANCEL, QUIT …).
 *                    Evidence, and the reason the row is auditable.
 * - `provider_message_id` — the provider's id for the inbound message, so the
 *                    opt-out can be traced back to a message in their console.
 *
 * There is deliberately NO message body and NO contact name here: a phone number
 * is PII and an SMS body may be anything; this table stores the minimum needed
 * to prove an opt-out was honoured.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_suppressions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // E.164, e.g. +16135550142. Never stored in any other shape.
            $table->string('phone_e164', 20);

            // stop_keyword | manual | provider
            $table->string('reason', 32)->default('stop_keyword');

            // The literal keyword received, when one was.
            $table->string('keyword', 32)->nullable();

            // Provider's id for the inbound message that caused this.
            $table->string('provider_message_id', 64)->nullable();

            $table->timestamp('suppressed_at');

            // Set by an inbound START. The row is NEVER deleted — a released
            // suppression is the record that the opt-out existed and was later
            // withdrawn by the subscriber themselves.
            $table->timestamp('released_at')->nullable();
            $table->string('released_keyword', 32)->nullable();

            $table->timestamps();

            // One suppression per number per tenant: a re-STOP updates the row
            // rather than appending a second, contradictory verdict — the same
            // reasoning as broadcast_deliveries' (broadcast_id, channel) key.
            $table->unique(['masjid_id', 'phone_e164']);

            // "Is this number suppressed anywhere?" — used by the operator
            // tooling and by the webhook when resolving an unknown sender.
            $table->index('phone_e164');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_suppressions');
    }
};
