<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores each device's OneSignal *subscription* id (a.k.a. player id).
     *
     * Push targeting by `external_id` alias (the device_id we send to OneSignal
     * via OneSignal.login) does NOT resolve in OneSignal's notification API
     * (returns invalid_aliases). Targeting by subscription_id works. So the app
     * now reports OneSignal.User.pushSubscription.id and we target that instead.
     */
    public function up(): void
    {
        Schema::table('mobile_app_users', function (Blueprint $table) {
            $table->string('onesignal_subscription_id')->nullable()->after('device_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mobile_app_users', function (Blueprint $table) {
            $table->dropColumn('onesignal_subscription_id');
        });
    }
};
