<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** NULL preserves the existing guide choice. INSTANT refuses any table-rewriting fallback. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `class_subjects` ADD COLUMN `guide_subjects` JSON NULL, ALGORITHM=INSTANT');
        } else {
            Schema::table('class_subjects', fn (Blueprint $table) => $table->json('guide_subjects')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('class_subjects', fn (Blueprint $table) => $table->dropColumn('guide_subjects'));
    }
};
