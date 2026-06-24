<?php

use App\Models\Hadith;
use App\Support\ArabicText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hadiths.title_normalized + hadiths.matn_normalized
 *
 * Diacritic-insensitive Arabic search (Task B). The mobile search endpoint does a
 * SQL LIKE over the Arabic title/matn, but the stored text carries tashkeel while
 * users type bare Arabic — so the LIKE misses. We add two normalized shadow columns
 * (tashkeel stripped, hamza/letter variants folded — see App\Support\ArabicText),
 * keep them in sync via the Hadith model's saving hook, and search against them.
 *
 * Indexable: a plain index on matn_normalized lets prefix matches use it; the
 * existing `%term%` leading-wildcard search still benefits from the smaller,
 * normalized haystack and from not having to REPLACE() at query time.
 *
 * Backfill: existing rows are normalized in place here so the column is correct
 * immediately after migrate (no separate backfill command needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hadiths', function (Blueprint $table) {
            $table->text('title_normalized')->nullable()->after('title');
            $table->text('matn_normalized')->nullable()->after('matn');
        });

        // Index the normalized matn for prefix/equality lookups. MySQL requires a
        // prefix length on TEXT indexes; 191 covers the typical search head. Done via
        // raw SQL because the Blueprint API can't express a prefix length on TEXT.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('CREATE INDEX hadiths_matn_normalized_idx ON hadiths (matn_normalized(191))');
        }

        // Backfill existing rows. Use the model so the same normalizer runs, but
        // saveQuietly to avoid firing observers/events during a migration.
        Hadith::query()->chunkById(200, function ($hadiths) {
            foreach ($hadiths as $hadith) {
                $hadith->title_normalized = ArabicText::normalize($hadith->title);
                $hadith->matn_normalized = ArabicText::normalize($hadith->matn);
                $hadith->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP INDEX hadiths_matn_normalized_idx ON hadiths');
        }

        Schema::table('hadiths', function (Blueprint $table) {
            $table->dropColumn(['title_normalized', 'matn_normalized']);
        });
    }
};
