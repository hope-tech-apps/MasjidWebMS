<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->string('question', 20);
            $table->string('label', 120);
            $table->unsignedInteger('position');
            $table->boolean('active')->default(true);
            // Immutable identity: renaming Other never changes its text rule.
            $table->boolean('is_other')->default(false);
            $table->timestamps();
            $table->index(['masjid_id','question','active','position'], 'mqo_list_idx');
        });
        Schema::create('member_question_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('occupation_id')->nullable()->constrained('member_question_options')->restrictOnDelete();
            // Encrypted casts expand plaintext; never use a bounded VARCHAR here.
            $table->text('occupation_text')->nullable();
            $table->text('help_text')->nullable();
            $table->timestamps();
            $table->unique(['masjid_id','contact_id'], 'mqa_contact_uq');
        });
        Schema::create('member_question_helps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('answer_id')->constrained('member_question_answers')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('member_question_options')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['answer_id','option_id'], 'mqh_option_uq');
        });
        Schema::create('app_volunteer_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->unique()->constrained('masjids')->cascadeOnDelete();
            $table->string('title', 30)->default('Volunteer');
            $table->text('url')->nullable();
            $table->timestamps();
        });

    }
    public function down(): void
    {
        Schema::dropIfExists('app_volunteer_settings');
        Schema::dropIfExists('member_question_helps');
        Schema::dropIfExists('member_question_answers');
        Schema::dropIfExists('member_question_options');
    }
};
