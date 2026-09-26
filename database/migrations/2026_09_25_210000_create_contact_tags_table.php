<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contact_tags + contact_tag_links — an organisation's own labels on its
 * contacts ("Volunteer", "Fall Festival 2024", "MEC emaillist 1").
 *
 * Built for the MEC Wix migration (the owner: "Build tags in Manara", so the
 * eleven Wix labels come across), but it is a Manara-wide feature: any
 * organisation's admins create tags, tag and untag contacts, filter the
 * directory by one and address a broadcast to one.
 *
 * ## Why the name has a second, normalised column
 *
 * Production MySQL collates `utf8mb4_bin`, which is case- and byte-exact. A
 * unique index on `name` alone would admit "Volunteer" beside "volunteer " as
 * two tags an admin cannot tell apart in a picker. `name_key` is the name
 * lower-cased with its whitespace collapsed (App\Models\ContactTag::keyFor),
 * and the unique index is on (masjid_id, name_key), so the rule is the same on
 * SQLite and MySQL and does not depend on a collation. `Str::slug` was not used
 * for the key: it reduces an Arabic name to an empty string.
 *
 * ## Why the links carry an import_batch and no masjid_id
 *
 * A link is reachable only through a tag and a contact, both of which carry
 * BelongsToMasjid; every write path resolves both through the bound tenant
 * first (ContactTagsController), so a denormalised masjid_id would be a second
 * copy of a fact with nothing to enforce it. `import_batch` records which
 * import run created the link, so `wix:import-contacts --undo` removes exactly
 * the links it added and never one an admin made by hand.
 *
 * Both foreign keys cascade: a hard-deleted contact (a merge's force-delete)
 * or a deleted tag takes its links with it. A SOFT-deleted contact keeps its
 * links, so restoring the contact restores its tags.
 *
 * Index names are written by hand, under MySQL's 64-character limit
 * (.claude/rules/migrations.md). Blueprint only, so no driver guard is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('name_key', 100);
            $table->timestamps();

            $table->unique(['masjid_id', 'name_key'], 'contact_tags_tenant_name_unique');
        });

        Schema::create('contact_tag_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_tag_id')->constrained('contact_tags')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('import_batch', 64)->nullable();
            $table->timestamps();

            $table->unique(['contact_tag_id', 'contact_id'], 'contact_tag_links_tag_contact_unique');
            $table->index('contact_id', 'contact_tag_links_contact_index');
            $table->index('import_batch', 'contact_tag_links_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tag_links');
        Schema::dropIfExists('contact_tags');
    }
};
