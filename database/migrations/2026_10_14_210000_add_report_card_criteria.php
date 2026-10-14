<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/** School curriculum and publication locks; populated rollback refuses destructive loss. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedTinyInteger('from_position');
            $table->unsignedTinyInteger('to_position');
            $table->unsignedInteger('position');
            $table->boolean('is_default')->default(false);
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->index(['masjid_id', 'archived', 'position'], 'rc_bands_school_order');
        });
        Schema::create('report_card_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $table->foreignId('band_id')->constrained('report_card_bands')->restrictOnDelete();
            $table->string('subject', 120);
            $table->string('kind', 20);
            $table->unsignedInteger('subject_position');
            $table->unsignedInteger('position');
            $table->text('text');
            $table->timestamps();
            $table->unique(['band_id', 'subject_position', 'position'], 'rc_criteria_band_order_unique');
        });
        Schema::create('report_card_criteria_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $table->date('effective_on');
            $table->string('effective_period', 80);
            $table->json('bands');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['masjid_id', 'effective_period'], 'rc_draft_school_period_unique');
        });
        Schema::create('report_card_criteria_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 120);
            $table->timestamps();
        });
        Schema::create('report_card_band_freezes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $table->foreignId('band_id')->constrained('report_card_bands')->restrictOnDelete();
            $table->string('period_key', 80);
            $table->timestamps();
            $table->unique(['band_id', 'period_key'], 'rc_band_period_freeze_unique');
        });
        Schema::table('report_cards', function (Blueprint $table) {
            $table->foreignId('criteria_band_id')->nullable()->constrained('report_card_bands')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('report_card_bands')->exists()) throw new RuntimeException('Criteria records exist. Switch off report-card criteria instead.');
        Schema::table('report_cards', fn (Blueprint $table) => $table->dropConstrainedForeignId('criteria_band_id'));
        foreach (['report_card_band_freezes','report_card_criteria_histories','report_card_criteria_drafts','report_card_criteria','report_card_bands'] as $table) Schema::dropIfExists($table);
    }
};
