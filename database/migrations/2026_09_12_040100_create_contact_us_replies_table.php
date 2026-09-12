<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contact_us_replies — what staff actually wrote back (PLAN T-042d).
 *
 * The reply endpoint has existed since the contact inbox was built and it
 * `Mail::to(...)->send(...)` and returned. Nothing was written down. Re-open the
 * message tomorrow and there is no record of what was said, by whom, or that it
 * was answered at all — which is the same office failure as the answered flag,
 * one layer deeper: the second person to open the message cannot see the first
 * person's answer even when they know one exists.
 *
 * ## Why the columns are shaped this way
 *
 *  - `sent_to` is a SNAPSHOT of the address the reply actually went to, not a
 *    join to contact_us_accounts.email. The account's email can be filled in or
 *    corrected later; the record of where a message was sent must not change
 *    underneath the person reading it.
 *  - `actor_user_id` + `actor_name` + `actor_email` for the same reason
 *    contact_us_messages carries both a FK and a name snapshot: the FK nulls
 *    when a staff account is deleted, and "who answered this person?" has to
 *    still have an answer afterwards.
 *  - `sent_at` is NULLABLE and is the delivery fact, distinct from
 *    `created_at`, which is only "we recorded that someone composed this". The
 *    controller writes the row FIRST and stamps `sent_at` only once the mailer
 *    has taken it, so a relay outage leaves a visible "recorded, not delivered"
 *    row instead of losing the text the admin typed. A row with a null `sent_at`
 *    is the one case where the message stays UNanswered.
 *
 * ## `idempotency_key` is the double-send guard
 *
 * This endpoint mails a member of the public. A double click, a browser retry,
 * or the same modal open in two tabs must not put two copies of the same reply
 * in a stranger's inbox — a disabled button is a UI courtesy, not a guarantee,
 * and it does nothing about the second tab or the retried request.
 *
 * The SPA mints one key per composed reply and rotates it only after a
 * DELIVERED send. The unique index below is what actually enforces it: two
 * concurrent requests race, exactly one INSERT survives, and the loser catches
 * the constraint violation, re-reads the winner's row and returns it without
 * sending anything. Nullable because the guard is opt-in for non-SPA callers
 * (NULLs never collide in a unique index on either driver), and a caller that
 * sends no key simply gets the old, unprotected behaviour rather than a 422.
 *
 * The composite unique index is NAMED BY HAND and is 38 characters. MySQL caps
 * identifiers at 64 and Laravel's generated name for this pair would be
 * `contact_us_replies_contact_us_message_id_idempotency_key_unique` — 63
 * characters, one migration edit away from dying halfway through a production
 * deploy. See .claude/rules/migrations.md.
 *
 * No `masjid_id`: a reply is scoped through its parent message, which is itself
 * hand-scoped through contacter -> mobileAppUser -> masjid_id. Adding the column
 * would oblige the model to take BelongsToMasjid (TenantScopingCoverageTest
 * layer 4) and its global scope would then apply on the unauthenticated intake
 * paths, where no tenant is ever bound. See .claude/rules/tenant-scoping.md and
 * the ContactUsReply model docblock.
 *
 * Blueprint only, so no driver guard is needed (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_us_replies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contact_us_message_id')->constrained()->cascadeOnDelete();

            $table->text('body');

            // The address this reply was actually sent to, as it stood then.
            $table->string('sent_to');

            // The staff member who wrote it. Nullable + nullOnDelete: the reply
            // is the organisation's record, not the author's.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();

            // See the class docblock: one key per composed reply, rotated only
            // after a delivered send.
            $table->string('idempotency_key', 64)->nullable();

            // Null until the mailer accepted it. Deliberately not `created_at`.
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['contact_us_message_id', 'idempotency_key'],
                'contact_us_replies_msg_idem_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_us_replies');
    }
};
