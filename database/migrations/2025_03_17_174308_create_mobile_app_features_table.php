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
        Schema::create('mobile_app_features', function (Blueprint $table) {
            $table->id();
            $name = $table->string('name')->unique();
            // MySQL-only collation; guard for sqlite portability (test suite).
            if (DB::getDriverName() === 'mysql') {
                $name->collation('utf8mb4_bin');
            }
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobile_app_features');
    }
};
