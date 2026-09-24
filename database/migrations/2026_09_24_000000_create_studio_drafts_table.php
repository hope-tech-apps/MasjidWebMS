<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manara Studio's draft: everything known about a new client between "New
 * client" and Step 3 (docs/manara-studio.md D7), so an abandoned onboarding
 * leaves no half-configured organisation in the counts.
 *
 * There is no masjid_id. A draft exists BEFORE its tenant, and the org it
 * becomes is recorded as `provisioned_masjid_id` so nobody mistakes the column
 * for a tenant key (TenantScopingCoverageTest lists the model as declined).
 *
 * `answers` holds the steps' sections; `name` and `org_type` are copies of
 * answers.identity kept as columns only so the drafts list can show them.
 * There is deliberately no column for store secrets: BYO credentials are typed
 * at Step 3 and sent in the provision body, never persisted here.
 *
 * `status` is a string checked against StudioDraft::STATUSES, never a DB enum,
 * for the reason Masjid::ORG_TYPES is (.claude/rules/verticals.md): a new value
 * needs no migration. Blueprint only, so no driver guard is needed
 * (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_drafts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('status', 16)->default('draft');
            $table->string('current_step', 32)->default('foundation');
            $table->unsignedSmallInteger('schema_version')->default(1);
            // Optimistic lock over `answers`: a PATCH must name the version it
            // read, so two tabs autosaving the same draft cannot clobber each
            // other silently.
            $table->unsignedInteger('lock_version')->default(0);

            $table->string('name', 255)->nullable();
            $table->string('org_type', 32)->nullable();
            $table->json('answers')->nullable();

            // The logo lives on a private disk; the path is random and the
            // uploader's filename is kept only here.
            $table->string('logo_disk', 32)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('logo_original_name', 255)->nullable();
            $table->string('logo_mime_type', 100)->nullable();
            $table->unsignedInteger('logo_size_bytes')->nullable();
            $table->unsignedSmallInteger('logo_width')->nullable();
            $table->unsignedSmallInteger('logo_height')->nullable();
            $table->char('logo_sha256', 64)->nullable();

            // Unique: one draft becomes at most one organisation and vice versa.
            $table->foreignId('provisioned_masjid_id')->nullable()->unique()->constrained('masjids')->nullOnDelete();
            $table->timestamp('provisioned_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The drafts list (newest first within a status) and the retention
            // purge (drafts untouched since a cutoff). Named by hand: MySQL caps
            // identifiers at 64 characters and SQLite does not.
            $table->index(['status', 'updated_at'], 'studio_drafts_status_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_drafts');
    }
};
