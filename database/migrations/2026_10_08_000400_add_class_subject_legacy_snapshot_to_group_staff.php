<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_staff', function (Blueprint $table) {
            $table->json('class_subject_legacy_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        \App\Support\ClassSubjectRollback::assertEmpty();
        Schema::table('group_staff', fn (Blueprint $table) => $table->dropColumn('class_subject_legacy_snapshot'));
    }
};
