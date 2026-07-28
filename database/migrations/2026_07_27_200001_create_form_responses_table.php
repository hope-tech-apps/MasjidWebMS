<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per submission.
 *
 * `data` holds the whole submission exactly as validated, keyed by field name, so a
 * form can change shape later without rewriting history — an old response still
 * renders against the schema version it was collected under.
 *
 * The respondent_* columns duplicate three values out of `data`. That denormalisation
 * is deliberate: the admin list has to be searchable and SORTABLE across the whole
 * result set, and every list in this app is server-paginated. Sorting on a JSON path
 * is not portable between MySQL (production) and SQLite (the test suite), so the three
 * fields an admin actually searches by get real, indexed columns. Which schema fields
 * feed them is declared in forms.settings.identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_responses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_id')->constrained('forms')->onDelete('cascade');
            // Denormalised tenant key so every admin query can scope by masjid without
            // joining `forms`, matching how the rest of the admin surface scopes.
            $table->foreignId('masjid_id')->constrained('masjids')->onDelete('cascade');

            $table->json('data');

            $table->string('respondent_name')->nullable();
            $table->string('respondent_email')->nullable();
            $table->string('respondent_phone')->nullable();

            // For repeatable sections (camp attendees, RSVP head count): how many
            // entries this submission represents. Drives capacity and the fee total.
            $table->unsignedInteger('entry_count')->default(1);

            // Computed from settings.fee at submission time and STORED, so a later
            // price change does not silently restate what someone already owed.
            $table->decimal('amount_due', 10, 2)->nullable();

            // Admin workflow state. String rather than a DB enum so a masjid can be
            // given new states later without a migration.
            $table->string('status')->default('new');
            $table->text('admin_notes')->nullable();

            // Abuse forensics + duplicate detection. Never shown in the admin list.
            $table->string('device_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('submitted_at');
            $table->timestamps();

            // The admin list's default query: this masjid's responses to one form,
            // newest first.
            $table->index(['masjid_id', 'form_id', 'submitted_at']);
            $table->index(['form_id', 'status']);
            $table->index('respondent_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_responses');
    }
};
