<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which organisation's Connect account charges this one's FORM card payments
 * (BISS Sunday School through Burlington Masjid; DECISIONS.md 2026-09-15).
 *
 *   forms_card_via_masjid_id   the holder organisation, or NULL (every existing row:
 *                              the organisation charges on its own account, or not
 *                              at all). Usable only when it equals `parent_id`, the
 *                              holder is live, onboarded and not itself linked, and
 *                              this organisation has no account of its own
 *                              (App\Services\Stripe\FormChargeAccount).
 *   forms_card_via_set_at      when a SuperAdmin last changed the link
 *   forms_card_via_set_by      who (a users id); the full history is the append-only
 *                              masjid_forms_card_links_log table
 *
 * The link lends nothing but the holder's account STRING, read live on every
 * resolution. No acct_ id is copied onto the child, so `stripe_account_id` stays
 * NULL there and masjids_active_stripe_account_unique keeps holding.
 *
 * And, for every organisation with a Connect account:
 *
 *   stripe_deauthorized_at     when the platform was last known to be disconnected from
 *                              the organisation's CURRENT stripe_account_id (Stripe's
 *                              account.application.deauthorized, or Stripe refusing a
 *                              pinned form page on it). While set, an account.updated
 *                              Stripe created at or before it is a late delivery and
 *                              cannot turn the charges flags back on; a later one (a
 *                              reconnect) clears it. NULL on every existing row.
 *
 * NO foreign key: adding one to `masjids` makes SQLite rebuild the table and drops
 * the partial unique indexes (.claude/rules/migrations.md). The index name is set by
 * hand (MySQL's 64-character identifier limit). Nullable with no default and no
 * backfill, so every existing organisation means exactly what it meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->unsignedBigInteger('forms_card_via_masjid_id')->nullable();
            $table->timestamp('forms_card_via_set_at')->nullable();
            $table->unsignedBigInteger('forms_card_via_set_by')->nullable();
            $table->timestamp('stripe_deauthorized_at')->nullable();

            $table->index('forms_card_via_masjid_id', 'masjids_forms_card_via_idx');
        });
    }

    public function down(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->dropIndex('masjids_forms_card_via_idx');
        });

        Schema::table('masjids', function (Blueprint $table) {
            $table->dropColumn(['forms_card_via_masjid_id', 'forms_card_via_set_at', 'forms_card_via_set_by', 'stripe_deauthorized_at']);
        });
    }
};
