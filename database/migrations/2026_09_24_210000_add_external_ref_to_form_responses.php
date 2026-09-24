<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a form response remember the record it was imported from.
 *
 * `alrazi:sync-website` copies the Al-Razi school website's registration and
 * careers submissions into two Manara forms, every five minutes. Each copy is
 * matched back to its source row by (form_id, external_ref), where external_ref
 * is the website's own row id, so a re-run updates in place instead of writing a
 * second copy.
 *
 *   external_ref        the source row's id. NOT the public `uuid`: the website
 *                       sends this id to the family's browser and it unlocks the
 *                       site's payment step, so it is hidden from every JSON
 *                       payload (FormResponse::$hidden) and never fillable.
 *   external_synced_at  when the import last wrote a change to this row.
 *
 * Both are nullable, so every response that exists today reads as "not imported".
 * NULLs are distinct in a unique index on both drivers, so those rows never
 * collide with each other.
 *
 * The index is named by hand: MySQL caps an identifier at 64 characters and
 * SQLite enforces nothing. No `->constrained()`: adding a foreign key to an
 * existing table rebuilds it on SQLite and drops partial indexes
 * (.claude/rules/migrations.md). Pinned by AlRaziWebsiteSyncTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->string('external_ref', 64)->nullable();
            $table->dateTime('external_synced_at')->nullable();

            $table->unique(['form_id', 'external_ref'], 'form_resp_form_external_ref_unique');
        });
    }

    public function down(): void
    {
        // The index first: SQLite refuses to drop a column an index still names.
        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropUnique('form_resp_form_external_ref_unique');
        });

        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropColumn(['external_ref', 'external_synced_at']);
        });
    }
};
