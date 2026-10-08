<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->json('subject_seed_grades')->nullable();
            $table->timestamp('class_subjects_initialized_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('groups')->whereNotNull('subject_seed_grades')->orWhereNotNull('class_subjects_initialized_at')->exists()
            || (Schema::hasTable('class_subjects') && DB::table('class_subjects')->exists())) {
            throw new RuntimeException('Refusing to remove class subject initialization data. Switch the capability off to roll back.');
        }
        Schema::table('groups', fn (Blueprint $table) => $table->dropColumn(['subject_seed_grades', 'class_subjects_initialized_at']));
    }
};
