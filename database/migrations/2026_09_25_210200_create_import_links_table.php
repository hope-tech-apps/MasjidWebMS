<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * import_links — which Manara row an external record became, per organisation.
 *
 * Written by the staged Wix importers (`wix:import-contacts`,
 * `wix:import-form-messages`). Three jobs, all of which the migration plan
 * needs and none of which `contacts.import_batch` alone can do:
 *
 *  - **A re-run UPDATES rather than duplicates.** The contact import is applied
 *    once from a fresh read-only pull the week before the domain move, and may
 *    be re-run from a later pull. Each Wix contact id is looked up here first,
 *    so the second run finds the contact the first one made — even after an
 *    admin corrected its email, which would defeat matching by address.
 *  - **Undo removes EXACTLY what the run created.** `created_local` separates a
 *    row the import made from an existing row it only matched, so `--undo`
 *    deletes the first and never the second.
 *  - **A re-run leaves an office edit alone.** `fingerprint` is a SHA-256 of
 *    the values the import last wrote; a contact whose current values no longer
 *    hash to it was edited in Manara, and the re-run keeps the edit.
 *
 * `local_id` is deliberately not a foreign key, and not named `contact_id`:
 * one table serves contacts, tags, contact-us accounts and messages, and a link
 * that outlives its row is information (the person was deleted in Manara after
 * the import, so a re-run must not recreate them).
 *
 * `external_id` is 191 characters because it sits in a composite unique index
 * on utf8mb4 MySQL, which SQLite would never enforce
 * (.claude/rules/migrations.md). Index names by hand, under 64 characters.
 * Blueprint only, so no driver guard is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('kind', 32);
            $table->string('external_id', 191);
            $table->unsignedBigInteger('local_id');
            $table->boolean('created_local')->default(false);
            $table->char('fingerprint', 64)->nullable();
            $table->string('import_batch', 64);
            $table->timestamps();

            $table->unique(['masjid_id', 'source', 'kind', 'external_id'], 'import_links_tenant_external_unique');
            $table->index(['masjid_id', 'import_batch'], 'import_links_tenant_batch_index');
            $table->index(['masjid_id', 'kind', 'local_id'], 'import_links_tenant_local_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_links');
    }
};
