<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_staff', function (Blueprint $table) {
            $table->json('class_subject_ids')->nullable();
            $table->timestamp('class_subjects_mapped_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('group_staff')->whereNotNull('class_subject_ids')->orWhereNotNull('class_subjects_mapped_at')->exists()) {
            throw new RuntimeException('Refusing to remove class subject assignments. Switch the capability off to roll back.');
        }
        Schema::table('group_staff', fn (Blueprint $table) => $table->dropColumn(['class_subject_ids', 'class_subjects_mapped_at']));
    }
};
