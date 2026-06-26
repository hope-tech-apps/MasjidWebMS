<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mobile_app_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->nullable()->constrained()->onDelete('set null');
            $deviceId = $table->string('device_id')->unique();
            // MySQL-only collation; guard for sqlite portability (test suite).
            if (DB::getDriverName() === 'mysql') {
                $deviceId->collation('utf8mb4_bin');
            }
            $table->string('user_agent');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobile_app_users');
    }
};
