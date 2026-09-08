<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tells a contact the office created apart from one that created itself.
 *
 * Until now every `contacts` row was staff-authored: an admin typed it, or an
 * import batch did. App sign-up inverts that — anyone who downloads a tenant's
 * app can cause a row — so the two populations have to stay distinguishable in
 * every admin list, or the CRM quietly stops being a curated roster.
 *
 * `signup_source` is NULL for every row that already exists, and stays NULL for
 * anything staff create. NULL therefore means "staff-authored" and is not a
 * missing value; only self-registration writes 'app'. Named `signup_source`
 * rather than `source` because `group_memberships` already carries a
 * `provenance` concept and an unqualified `source` reads like it.
 *
 * `verified_at` records that somebody proved control of `login_email` by
 * redeeming a code sent to it. It is NOT the same as `login_enabled_at`, which
 * is the office GRANTING access; a self-registered member has verified_at set
 * and was never granted anything by staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('signup_source', 32)->nullable()->after('import_batch');
            $table->timestamp('verified_at')->nullable()->after('signup_source');
            $table->index(['masjid_id', 'signup_source'], 'contacts_masjid_signup_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_masjid_signup_source_index');
            $table->dropColumn(['signup_source', 'verified_at']);
        });
    }
};
