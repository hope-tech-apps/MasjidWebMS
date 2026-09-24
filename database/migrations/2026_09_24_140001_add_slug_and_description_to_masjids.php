<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two identity columns Manara Studio writes (S3 adds them; S8 fills them).
 *
 * - `slug` names the organisation's managed host (`<slug>.manara.hopetechapps.com`)
 *   and, in W3, its client repositories. 63 characters because it is one DNS
 *   label. Unique, and nullable because every organisation made before Studio
 *   has none; MySQL and SQLite both treat NULLs as distinct in a unique index.
 * - `description` is public copy in the client's own words: the renderer's
 *   lookup, its hero subtitle and its meta description publish it verbatim
 *   (R12). `text`, because its length is the client's to decide.
 *
 * Both are on Masjid::PUBLIC_DIRECTORY_DENYLIST, so neither reaches the mobile
 * directory or /api/mobile/masjids/{id}; PublicPayloadKeysUnchangedTest pins
 * those payloads. The only public reader of `description` is the by-host
 * lookup, which builds its payload from an allowlist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            if (! Schema::hasColumn('masjids', 'slug')) {
                $table->string('slug', 63)->nullable()->unique('masjids_slug_unique');
            }

            if (! Schema::hasColumn('masjids', 'description')) {
                $table->text('description')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('masjids', 'slug')) {
            Schema::table('masjids', function (Blueprint $table) {
                $table->dropUnique('masjids_slug_unique');
                $table->dropColumn('slug');
            });
        }

        if (Schema::hasColumn('masjids', 'description')) {
            Schema::table('masjids', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
