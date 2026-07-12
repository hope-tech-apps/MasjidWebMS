<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-masjid CRM feature gate.
 *
 * The whole CRM (member directory + donation money path) is OFF for every masjid
 * until a SuperAdmin turns it on. Default false = the gate is closed, so the flag
 * is purely additive: existing masjids get no CRM access on migrate, and the CRM
 * endpoints (see the `crm` middleware + routes/admin.php) 403 until this is true.
 * Only the SuperAdmin crm-access toggle can flip it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->boolean('crm_enabled')->default(false)->after('stripe_payouts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->dropColumn('crm_enabled');
        });
    }
};
