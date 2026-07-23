<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-masjid OneSignal push config (additive to masjid_app_publishing).
 *
 * Each masjid app tags its OneSignal subscriptions with `masjid_id`. To move
 * the fleet off a single SHARED OneSignal app toward per-masjid apps (stronger
 * tenant isolation + required correctness once subscription IDs become
 * app-scoped), we store an optional per-masjid OneSignal app id + REST API key:
 *
 *   - onesignal_app_id        — the OneSignal application id. NOT a secret: it
 *     is embedded in the mobile client, so it is safe to read back over the API.
 *   - onesignal_rest_api_key  — the app-scoped REST API key. A SECRET. Stored
 *     ENCRYPTED at the model layer (Laravel `encrypted` cast on
 *     App\Models\MasjidAppPublishing), so this column holds ciphertext, never
 *     plaintext. Also `$hidden` on the model and NEVER echoed by any API — read
 *     paths expose only the boolean `has_onesignal_key`. `text` for ciphertext
 *     headroom (encryption + base64 inflates the payload), mirroring asc_key_p8.
 *
 * BOTH nullable + default-null: masjids without per-masjid config keep using the
 * global/shared OneSignal app (current behavior) unchanged. Purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_app_publishing', function (Blueprint $table) {
            // Public identifier — safe to expose. Nullable: only set once the
            // masjid has its own OneSignal app provisioned.
            $table->string('onesignal_app_id')->nullable()->after('play_service_account_json');

            // Secret REST API key — encrypted-at-rest ciphertext (see model).
            $table->text('onesignal_rest_api_key')->nullable()->after('onesignal_app_id');
        });
    }

    public function down(): void
    {
        Schema::table('masjid_app_publishing', function (Blueprint $table) {
            $table->dropColumn(['onesignal_app_id', 'onesignal_rest_api_key']);
        });
    }
};
