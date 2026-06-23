<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks when a device last checked in (app launch / background refresh).
     * The server-side prayer backstop uses this to target ONLY devices that
     * have gone dark — i.e. whose local notification window has likely lapsed —
     * so active devices keep their precise local notifications with no double-up.
     */
    public function up(): void
    {
        Schema::table('mobile_app_users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->after('user_agent')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mobile_app_users', function (Blueprint $table) {
            $table->dropColumn('last_active_at');
        });
    }
};
