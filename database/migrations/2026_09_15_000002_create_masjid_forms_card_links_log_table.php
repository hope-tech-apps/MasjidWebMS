<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger of every change to where an organisation's FORM card payments go
 * (DECISIONS.md 2026-09-15).
 *
 * `masjids.forms_card_via_masjid_id` lets a child program org (BISS) take form
 * card payments through its parent's Connect account (Burlington Masjid). That
 * moves money under another tenant's merchant record, so who switched it on, on
 * whose consent, against which account, and who switched it off must outlive the
 * row it describes. `forms_card_via_set_at` / `_set_by` only describe the LAST
 * write (an unlink overwrites them) and the warning log rotates in 14 days, so
 * neither is an audit trail. This table is.
 *
 * Column choices:
 *  - No foreign keys. A row must survive the force delete of either organisation
 *    and of the acting user; a cascade would erase the history of the very act a
 *    reviewer is asking about, and a FK onto `masjids` would make SQLite rebuild
 *    that table and drop its partial unique indexes.
 *  - `action` is a string (`link|unlink|revoke`), never a DB enum, like every
 *    other discriminator in this schema.
 *  - `holder_account_suffix` is the last four characters of the holder's
 *    `acct_…` at the time of the act. Enough to tell a re-onboarded account from
 *    the old one; never the whole id.
 *  - `created_at` only. Append-only is enforced on the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masjid_forms_card_links_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('child_masjid_id');
            $table->unsignedBigInteger('holder_masjid_id');
            $table->string('action', 16);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('typed_name')->nullable();
            $table->text('consent_reference')->nullable();
            $table->string('holder_account_suffix', 8)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['child_masjid_id', 'created_at'], 'mfcl_child_created_idx');
            $table->index(['holder_masjid_id', 'created_at'], 'mfcl_holder_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masjid_forms_card_links_log');
    }
};
