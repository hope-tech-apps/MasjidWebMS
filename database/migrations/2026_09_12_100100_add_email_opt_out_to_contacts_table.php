<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contacts.email_opted_out_at — the DISPLAY MIRROR of `email_suppressions`
 * (T-042c).
 *
 * The authority is the suppression row, which has no foreign key to `contacts`
 * precisely so a merge, a re-import or a delete cannot un-say an unsubscribe.
 * This column exists for one reason: so the member directory can show "this
 * person receives no announcement emails" without a join, and so staff stop
 * filing a ticket asking why somebody hears nothing.
 *
 * It is the same relationship `contacts.sms_opted_out_at` has with
 * `sms_suppressions`, and it carries the same warning: **nothing may read this
 * column to decide whether to send.** The audience resolver consults the
 * suppression table, never this. A column on a mortal row is a display fact, not
 * a compliance fact — clearing it by hand, or losing it to a force-delete,
 * changes nothing about who is suppressed.
 *
 * Nullable timestamp, no default, no backfill: every existing contact predates
 * the unsubscribe link and none of them has asked to stop.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('email_opted_out_at')->nullable()->after('sms_opted_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('email_opted_out_at');
        });
    }
};
