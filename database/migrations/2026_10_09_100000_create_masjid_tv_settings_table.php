<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * masjid_tv_settings: one optional row per organisation holding what it chose
 * for its lobby TV board (the "TV Display" page, owner 2026-10-03).
 *
 * EVERY setting column is nullable and has NO database default. No row, or a
 * null column, means "what the board got before this table existed": the
 * constants on TvConfigController and the two values it derives per request
 * (the prayer panel from the organisation's type, the donation code from its
 * donation link). So an organisation that never opens the page is served a
 * byte-identical tv-config, and a column default could not express a derived
 * value at all. The one place that turns a row into what the board receives is
 * App\Support\TvBoard.
 *
 * The three switches hold only `false` or null: a board shows its slides, its
 * prayer times and its donation code unless the organisation turned one OFF.
 * The two texts are short strings with a server-side `max:` rule
 * (TvBoard::HEADER_TITLE_MAX, ::DONATE_CAPTION_MAX); the test asserts varchar.
 *
 * `updated_by_user_id` is not a foreign key, so deleting a departed
 * administrator's login cannot take a board's settings with it.
 *
 * `masjid_id` is UNIQUE (one row per organisation), named by hand, well under
 * MySQL's 64-character identifier limit. A NEW table, so ->constrained() is
 * safe on SQLite. Additive, Blueprint only, no raw SQL, no personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masjid_tv_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();

            $table->boolean('is_enabled')->nullable();
            $table->string('header_title', 60)->nullable();
            $table->unsignedSmallInteger('carousel_interval_seconds')->nullable();
            $table->boolean('show_prayer_panel')->nullable();
            $table->boolean('show_qr')->nullable();
            $table->string('donate_caption', 40)->nullable();

            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('masjid_id', 'masjid_tv_settings_masjid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masjid_tv_settings');
    }
};
