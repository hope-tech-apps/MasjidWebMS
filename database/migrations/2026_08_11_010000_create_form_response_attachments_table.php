<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One uploaded file belonging to one submission.
 *
 * The bytes live on a PRIVATE disk (config('forms.attachments.disk'), which is
 * storage/app/private, not the web-exposed public root). This table is the only
 * map from a form_response to those bytes, and the only place the respondent's
 * original filename is kept — the path on disk is random, so an attachment cannot
 * be found by guessing a name.
 *
 * `masjid_id` is denormalised here for the same reason it is on form_responses:
 * every read of a résumé should carry the tenant predicate without joining two
 * tables to find it. MySQL has no row-level security, so that predicate is the
 * whole guarantee.
 *
 * Blueprint only — nothing here needs raw SQL, so nothing here needs a driver
 * guard (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_response_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_response_id')->constrained('form_responses')->onDelete('cascade');
            // Denormalised tenant key. The cascade is a backstop only: deleting a
            // response deletes its attachments through the model (FormResponse's
            // `deleted` hook) so the FILES go with them — a DB-level cascade fires
            // no model events and would leave orphaned bytes on disk forever.
            $table->foreignId('masjid_id')->constrained('masjids')->onDelete('cascade');

            // Which schema field this file answers, e.g. `resume`.
            $table->string('field');

            // What the respondent called it. Shown to the admin and used as the
            // download filename; never used to build a path.
            $table->string('original_name');

            // Sniffed from the bytes at upload time, not taken from the client.
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size_bytes');

            // The disk is stored per-row rather than read from config at download
            // time, so moving the config later cannot orphan what is already here.
            $table->string('disk', 32);
            $table->string('path');

            $table->timestamps();

            // One file per question per submission: a second upload to the same
            // field replaces the first rather than accumulating.
            $table->unique(['form_response_id', 'field']);
            // The admin download path: this tenant's attachment, by id.
            $table->index(['masjid_id', 'form_response_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_response_attachments');
    }
};
