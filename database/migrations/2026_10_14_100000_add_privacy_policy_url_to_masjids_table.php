<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The address of the organisation's own privacy policy, beside its store links.
     *
     * A store listing under an organisation's own account needs a privacy policy the app can
     * open (Apple 5.1.1(i): in the listing AND inside the app). The apps show a "Privacy Policy"
     * row only when the organisation payload carries this address, so null is "no row".
     */
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->string('privacy_policy_url')->nullable()->after('google_play_link');
        });
    }

    public function down(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->dropColumn('privacy_policy_url');
        });
    }
};
