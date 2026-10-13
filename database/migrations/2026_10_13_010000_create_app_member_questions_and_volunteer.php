<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('CREATE TRIGGER member_questions_login_removed AFTER UPDATE OF verified_at, login_enabled_at ON contacts
                WHEN (OLD.verified_at IS NOT NULL AND NEW.verified_at IS NULL)
                  OR (OLD.login_enabled_at IS NOT NULL AND NEW.login_enabled_at IS NULL)
                BEGIN DELETE FROM member_question_answers WHERE contact_id = NEW.id AND masjid_id = NEW.masjid_id; END');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::unprepared('CREATE TRIGGER member_questions_login_removed AFTER UPDATE ON contacts FOR EACH ROW
                BEGIN IF (OLD.verified_at IS NOT NULL AND NEW.verified_at IS NULL)
                  OR (OLD.login_enabled_at IS NOT NULL AND NEW.login_enabled_at IS NULL) THEN
                DELETE FROM member_question_answers WHERE contact_id = NEW.id AND masjid_id = NEW.masjid_id;
                END IF; END');
        } else {
            throw new \RuntimeException('Member question erasure requires a supported database driver.');
        }
    }
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS member_questions_login_removed');
        Schema::dropIfExists('app_volunteer_settings');
        Schema::dropIfExists('member_question_helps');
        Schema::dropIfExists('member_question_answers');
        Schema::dropIfExists('member_question_options');
    }
};
