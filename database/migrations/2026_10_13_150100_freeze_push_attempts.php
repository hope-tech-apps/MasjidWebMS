<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_send_groups', function (Blueprint $table) {
            $table->json('subscription_ids')->nullable();
            $table->timestamp('attempted_at')->nullable();
        });
        Schema::table('push_sends', function (Blueprint $table) {
            $table->string('request_key', 128)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('push_sends', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropColumn('request_key');
        });
        Schema::table('push_send_groups', function (Blueprint $table) {
            $table->dropColumn(['subscription_ids', 'attempted_at']);
        });
    }
};
