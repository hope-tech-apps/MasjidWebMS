<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * library_azkar
 *
 * Curated, GLOBAL preset adhkar (morning/evening etc.) NOT tied to any masjid.
 * Copied 1:1 into the live `azkar` table on "add to collection".
 *
 * Columns mirror `azkar` (title/text/bless json {en,ar}, pronunciation, frequency,
 * reference). Instead of the masjid-side `azkar_category_id` FK, presets carry a
 * freeform `category` tag (e.g. "morning" / "evening") — the existing azkar schema
 * uses a category, so the library mirrors that with a type tag. The category is
 * copied through to a matching/auto-created AzkarCategory at copy time.
 *
 * `slug` is the stable natural key for idempotent seeding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_azkar', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();           // stable natural key for idempotent seeding
            $table->string('category')->nullable();     // morning / evening / general
            $table->json('title');                      // { en, ar } — matches azkar.title
            $table->json('text');                       // { en, ar }
            $table->json('bless')->nullable();          // { en, ar }
            $table->string('pronunciation');            // transliteration
            $table->unsignedInteger('frequency')->nullable(); // repeat count
            $table->string('reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_azkar');
    }
};
