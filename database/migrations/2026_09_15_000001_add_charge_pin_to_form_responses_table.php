<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account a form registration's card page was opened on, pinned on the row
 * (DECISIONS.md 2026-09-15).
 *
 * A registration of an organisation whose form card payments are charged through
 * another organisation's Connect account (BISS through Burlington Masjid) records, in
 * the same save as its idempotency key and BEFORE Stripe is called:
 *
 *   charge_account_id   the acct_ string the page was opened on. Every later read,
 *                       close and webhook for that page uses THIS, never whatever the
 *                       organisations are linked to now. Hidden on the model, and
 *                       nulled by the staging scrub.
 *   charge_masjid_id    the organisation holding that account when the page opened
 *   charge_ref          a random opaque reference, the only routing key a linked
 *                       charge's metadata carries. The public uuid is a bearer handle
 *                       and the holder's Stripe users can read metadata, so the uuid is
 *                       never sent. Unique, so the webhook finds the row by it alone.
 *   charge_expires_at   when that page stops taking payments (expires_at sent to Stripe)
 *   charge_flag         'refunded' | 'disputed': the holder refunded, or a payer disputed,
 *   charge_flagged_at   the charge in the holder's Stripe dashboard. Never changes
 *                       payment_status; it tells the organisation to look.
 *   charge_refunded_minor  how much of that charge has been refunded so far (Stripe's
 *                       charge.amount_refunded, minor units). A partial refund (the card
 *                       fee back, say) is told apart from a full one by comparing it
 *                       with total_minor; a later, larger refund raises it.
 *
 * NULL on every existing row and on every row of an organisation that is not linked:
 * those take today's path unchanged. Pins are never cleared.
 *
 * Plain columns: no foreign key (a SQLite rebuild would drop partial indexes;
 * .claude/rules/migrations.md), index names set by hand, no `->after()`.
 * string(64) holds an acct_ id with room to spare; string(40) a 32-hex reference with
 * its prefix; string(16) the flag, the `payment_method` precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->string('charge_account_id', 64)->nullable();
            $table->unsignedBigInteger('charge_masjid_id')->nullable();
            $table->string('charge_ref', 40)->nullable();
            $table->timestamp('charge_expires_at')->nullable();
            $table->string('charge_flag', 16)->nullable();
            $table->timestamp('charge_flagged_at')->nullable();
            $table->unsignedInteger('charge_refunded_minor')->nullable();

            $table->unique('charge_ref', 'form_responses_charge_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropUnique('form_responses_charge_ref_unique');
        });

        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropColumn([
                'charge_account_id',
                'charge_masjid_id',
                'charge_ref',
                'charge_expires_at',
                'charge_flag',
                'charge_flagged_at',
                'charge_refunded_minor',
            ]);
        });
    }
};
