<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('name_key', 64);
            $table->json('previous_name_keys')->nullable();
            $table->string('guide_subject', 64)->nullable();
            $table->string('tool', 32)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'name_key'], 'cs_group_name_uq');
            // NULL permits several subjects without a tool; hidden holders still reserve it.
            $table->unique(['group_id', 'tool'], 'cs_group_tool_uq');
            $table->index(['group_id', 'position'], 'cs_group_position_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('class_subjects')->exists()) {
            throw new RuntimeException('Refusing to drop populated class subjects. Switch the capability off to roll back.');
        }
        Schema::dropIfExists('class_subjects');
    }
};
