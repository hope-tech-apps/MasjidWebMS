<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * masjid_app_publishing
 *
 * Per-masjid, per-platform (iOS / Android / web) app-publishing configuration
 * captured by the Super-Admin onboarding wizard. One row per masjid (unique
 * masjid_id, cascade on delete).
 *
 * Each platform has an `account_mode`:
 *   - `managed` (default) — Hope Tech / the org publishes the app under its own
 *     Apple team / Google Play account. This is the paid tier; no credentials
 *     are stored for the masjid.
 *   - `byo` ("bring your own") — the masjid publishes under its OWN developer
 *     accounts and supplies the credentials below.
 *
 * The BYO credentials are SECRETS. They are stored ENCRYPTED at the model layer
 * (Laravel `encrypted` cast on App\Models\MasjidAppPublishing), so these columns
 * hold ciphertext, never plaintext. They are also `$hidden` on the model and are
 * NEVER returned by any API response — the wizard/read paths expose only booleans
 * (has_asc_key / has_play_service_account). Columns are text/longText for
 * ciphertext headroom (encryption inflates the payload).
 *
 * Mirrors theme_settings (per-masjid one-to-one config) and the two_factor_secret
 * column on users (encrypted-at-rest secret) — see those migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masjid_app_publishing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->unique()->constrained()->onDelete('cascade');

            // Per-platform account mode. Default managed = the org publishes it.
            $table->enum('ios_account_mode', ['managed', 'byo'])->default('managed');
            $table->enum('android_account_mode', ['managed', 'byo'])->default('managed');
            $table->enum('web_account_mode', ['managed', 'byo'])->default('managed');

            // BYO Apple App Store Connect API key (only set when ios_account_mode
            // is `byo`). Stored encrypted; text for ciphertext headroom.
            $table->text('asc_key_p8')->nullable();
            $table->text('asc_key_id')->nullable();
            $table->text('asc_issuer_id')->nullable();

            // BYO Google Play service-account JSON (only set when
            // android_account_mode is `byo`). Stored encrypted; longText because
            // the raw JSON is a few KB and encryption + base64 inflates it.
            $table->longText('play_service_account_json')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masjid_app_publishing');
    }
};
