<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * library_tasbeehs
 *
 * Curated, GLOBAL preset tasbeeh/dhikr counters NOT tied to any masjid. Copied 1:1
 * into the live `tasabih` table on "add to collection".
 *
 * Columns mirror `tasabih` (text json {en,ar}, pronunciation, reference). The
 * `tasabih` table has no count column, so the sensible default count lives only in
 * the library as `default_count` (informational — surfaced to the admin in the
 * picker; not copied since there is nowhere to put it on the live row).
 *
 * `slug` is the stable natural key for idempotent seeding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_tasbeehs', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();           // stable natural key for idempotent seeding
            $table->json('text');                       // { en, ar } — matches tasabih.text
            $table->string('pronunciation');            // transliteration
            $table->string('reference')->nullable();
            $table->unsignedInteger('default_count')->nullable(); // sensible default counter (library-only)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_tasbeehs');
    }
};
