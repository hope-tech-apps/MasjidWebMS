<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who released an email suppression, and on what evidence.
 *
 * Until now a release had exactly one author, the subscriber on their own
 * unsubscribe page, so `released_at` alone said everything. An import's
 * `not_opted_in` precaution (App\Services\Imports\WixContactImport) is not a
 * request the person made, and the person it silences never receives the
 * broadcast whose link would let them lift it. Staff may now lift THAT reason
 * only, when the person gives consent in Manara, and the row has to say so:
 *
 *  - `release_source` — null for the subscriber's own link (every release
 *    before this column), `staff_recorded_consent` for the staff path;
 *  - `release_evidence` — the staff member's own words for how consent was
 *    given ("signed the newsletter sheet at Jumu'ah, 3 Oct"), the same kind of
 *    record as `contacts.sms_consent_evidence`;
 *  - `released_by_user_id` — the staff account; null on delete so removing a
 *    user never removes the record that consent was taken.
 *
 * Additive and nullable, so every existing row reads as before. Blueprint only;
 * the foreign key's index name is Laravel's default, 46 characters.
 * email_suppressions is emptied on staging (config/staging_scrub.php), so the
 * evidence text never leaves production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_suppressions', function (Blueprint $table) {
            $table->string('release_source', 32)->nullable()->after('released_at');
            $table->string('release_evidence', 500)->nullable()->after('release_source');
            $table->foreignId('released_by_user_id')->nullable()->after('release_evidence')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_suppressions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('released_by_user_id');
            $table->dropColumn(['release_source', 'release_evidence']);
        });
    }
};
