<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giving a device an identity — the fix .claude/rules/broadcasts.md prescribes.
 *
 * That rule explains why push could only ever be all-devices-or-nothing:
 *
 *     "`mobile_app_users` has `device_id` and `onesignal_subscription_id` and no
 *      `contact_id` — there is no join from a person to their phone. Accepting
 *      [push + a narrowed audience] and broadcasting to every device would tell
 *      an admin they had sent something narrow when they had not. Do not 'fix'
 *      this by widening it; fix it, if ever, by giving devices an identity."
 *
 * This is that fix, and nothing else changes: push to `everyone` still means
 * every registered device, and push to a `contacts` audience is still refused,
 * because a chosen list of people is not the same question as a device somebody
 * signed into.
 *
 * ---------------------------------------------------------------------------
 * NULLABLE IS THE NORMAL STATE, NOT A MIGRATION GAP
 * ---------------------------------------------------------------------------
 * Every row is NULL today and most rows stay NULL forever: the app is usable
 * without an account, and a guest's phone is a device with no person behind it.
 * A device gains a `contact_id` only when a member signs in ON that device, and
 * loses it on sign-out. So NULL means "nobody has claimed this handset", which
 * is exactly what an interest-routed send must skip.
 *
 * `nullOnDelete` rather than cascade: deleting a contact must not delete the
 * device registration. The handset still exists and still receives `everyone`
 * broadcasts; it has simply stopped being anybody's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_app_users', function (Blueprint $table) {
            $table->foreignId('contact_id')
                ->nullable()
                ->after('masjid_id')
                ->constrained()
                ->nullOnDelete();

            // The send path's query: every device belonging to this set of
            // people, in this masjid.
            $table->index(['masjid_id', 'contact_id'], 'mobile_app_users_masjid_contact_index');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_app_users', function (Blueprint $table) {
            $table->dropIndex('mobile_app_users_masjid_contact_index');
            $table->dropForeign(['contact_id']);
            $table->dropColumn('contact_id');
        });
    }
};
