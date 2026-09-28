<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manara Studio W2 S14 (D9): each organisation's own OneSignal app, and the app
 * identities it is configured for.
 *
 * - ios_bundle_id / android_application_id: the organisation's app identities.
 *   OneSignal's iOS push needs the bundle id (apns_bundle_id), and S17 writes
 *   both when Studio generates the apps. Unique, so two organisations can never
 *   share an identity; NULLs do not collide on either driver, so every
 *   organisation without an app is fine. The index names are the defaults,
 *   well under MySQL's 64-character limit.
 * - onesignal_provisioned_at: when this organisation's own OneSignal app was
 *   created by Studio (null for every live organisation, which use the shared app).
 * - onesignal_platforms: the platforms that app has been configured for, as a
 *   JSON array ("ios" = APNs added, "android" = FCM added), so a platform added
 *   later is configured on the existing app instead of a second one.
 *
 * Additive and nullable only: no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_app_publishing', function (Blueprint $table) {
            $table->string('ios_bundle_id', 155)->nullable()->unique()->after('development_team');
            $table->string('android_application_id', 150)->nullable()->unique()->after('ios_bundle_id');
            $table->timestamp('onesignal_provisioned_at')->nullable()->after('onesignal_rest_api_key');
            $table->json('onesignal_platforms')->nullable()->after('onesignal_provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('masjid_app_publishing', function (Blueprint $table) {
            $table->dropUnique(['ios_bundle_id']);
            $table->dropUnique(['android_application_id']);
            $table->dropColumn(['ios_bundle_id', 'android_application_id', 'onesignal_provisioned_at', 'onesignal_platforms']);
        });
    }
};
