<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Hidden retention hints ride on rows already read by the legacy paths.
        foreach (['forms','school_years'] as $table) Schema::table($table, fn (Blueprint $t) => $t->boolean('has_duty_rota_records')->default(false));
        Schema::table('forms', fn (Blueprint $t) => $t->boolean('duty_rota_enabled')->default(false));
        Schema::create('duty_rotas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('school_year_id');
            $t->string('name',120); $t->unsignedTinyInteger('capacity')->default(3); $t->unsignedSmallInteger('turns_owed')->default(2);
            $t->unsignedBigInteger('source_form_id')->nullable(); $t->string('source_field',120)->nullable();
            $t->unsignedTinyInteger('reminder_lead')->default(3); $t->text('reminder_line')->nullable(); $t->boolean('active')->default(true); $t->timestamps();
            $t->index(['masjid_id','active'],'dr_school_active');
            $t->foreign('masjid_id','dr_school_fk')->references('id')->on('masjids')->restrictOnDelete();
            $t->foreign('school_year_id','dr_year_fk')->references('id')->on('school_years')->restrictOnDelete();
            $t->foreign('source_form_id','dr_form_fk')->references('id')->on('forms')->restrictOnDelete();
        });
        Schema::create('duty_rota_families', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('rota_id'); $t->char('identity_hash',64);
            $t->text('name'); $t->text('email')->nullable(); $t->text('phone')->nullable(); $t->timestamp('submitted_at')->nullable();
            $t->unsignedInteger('registration_count')->default(0); $t->boolean('withdrawn')->default(false); $t->json('unplaced_picks')->nullable(); $t->json('notices')->nullable();
            $t->char('token_hash',64)->nullable(); $t->char('issuance_nonce',64)->nullable(); $t->timestamp('token_expires_at')->nullable(); $t->timestamp('link_sent_at')->nullable(); $t->timestamps();
            $t->unique(['rota_id','identity_hash'],'drf_identity_unique'); $t->index(['masjid_id','rota_id'],'drf_school_rota');
            $t->foreign('masjid_id','drf_school_fk')->references('id')->on('masjids')->restrictOnDelete();
            $t->foreign('rota_id','drf_rota_fk')->references('id')->on('duty_rotas')->restrictOnDelete();
        });
        Schema::create('duty_rota_assignments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('rota_id'); $t->unsignedBigInteger('family_id'); $t->date('duty_on'); $t->string('source',10);
            $t->timestamp('released_at')->nullable(); $t->text('release_reason')->nullable(); $t->timestamp('reminder_queued_at')->nullable(); $t->timestamp('reminder_sent_at')->nullable(); $t->timestamps();
            $t->unique(['rota_id','family_id','duty_on'],'dra_family_day_unique'); $t->index(['masjid_id','rota_id','duty_on'],'dra_school_rota_day');
            $t->foreign('masjid_id','dra_school_fk')->references('id')->on('masjids')->restrictOnDelete();
            $t->foreign('rota_id','dra_rota_fk')->references('id')->on('duty_rotas')->restrictOnDelete();
            $t->foreign('family_id','dra_family_fk')->references('id')->on('duty_rota_families')->restrictOnDelete();
        });
        Schema::create('duty_rota_mails', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('masjid_id'); $t->unsignedBigInteger('rota_id'); $t->unsignedBigInteger('family_id'); $t->unsignedBigInteger('assignment_id')->nullable();
            $t->string('kind',20); $t->string('dedupe_key',100)->unique('drm_once_unique'); $t->string('status',10)->default('queued'); $t->timestamp('queued_at'); $t->timestamp('sent_at')->nullable(); $t->timestamp('failed_at')->nullable(); $t->timestamps();
            $t->index(['masjid_id','rota_id'],'drm_school_rota');
            $t->foreign('masjid_id','drm_school_fk')->references('id')->on('masjids')->restrictOnDelete();
            $t->foreign('rota_id','drm_rota_fk')->references('id')->on('duty_rotas')->restrictOnDelete();
            $t->foreign('family_id','drm_family_fk')->references('id')->on('duty_rota_families')->restrictOnDelete();
            $t->foreign('assignment_id','drm_assignment_fk')->references('id')->on('duty_rota_assignments')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        foreach (['duty_rota_mails','duty_rota_assignments','duty_rota_families','duty_rotas'] as $t) Schema::dropIfExists($t);
        Schema::table('forms',fn(Blueprint $t)=>$t->dropColumn('duty_rota_enabled'));
        foreach (['forms','school_years'] as $t) Schema::table($t,fn(Blueprint $b)=>$b->dropColumn('has_duty_rota_records'));
    }
};
