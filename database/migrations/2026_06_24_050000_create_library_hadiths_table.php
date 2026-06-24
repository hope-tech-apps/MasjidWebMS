<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * library_hadiths
 *
 * Curated, GLOBAL preset hadiths NOT tied to any masjid. An admin browses these in
 * the dashboard and "adds to collection" — which COPIES the row 1:1 into the live
 * `hadiths` table (where it becomes a normal, editable/deletable row).
 *
 * Columns mirror `hadiths` so the copy is field-for-field, with two differences:
 *   - No `show_date`: presets aren't dated; a show_date is assigned at copy time
 *     (defaults to the next free future date) since `hadiths.show_date` is unique.
 *   - `category` is a freeform label (e.g. "40 Hadith Nawawi") instead of the
 *     masjid-side `hadith_category_id` FK, because presets predate any masjid's
 *     category rows. `source` records the collection/narrator origin.
 *
 * `slug` is the stable natural key the seeder keys updateOrCreate on, so re-running
 * ContentLibrarySeeder is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_hadiths', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();           // stable natural key for idempotent seeding
            $table->string('category')->nullable();     // freeform label e.g. "40 Hadith Nawawi"
            $table->string('source')->nullable();       // collection / narrator origin
            $table->string('title');
            $table->text('isnad')->nullable();
            $table->text('matn');                       // Arabic text
            $table->json('strength');                   // { en, ar } — matches hadiths.strength
            $table->json('muhaddith');                  // { en, ar }
            $table->json('references');                 // [ { title, reference } ]
            $table->text('description');                // English translation / explanation
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_hadiths');
    }
};
