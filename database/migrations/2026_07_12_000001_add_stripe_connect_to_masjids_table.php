<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe Connect (Standard account) linkage on the masjid.
 *
 * Each masjid is its own Stripe merchant of record. `stripe_account_id` is the
 * connected (Standard) account id (acct_...). The two boolean flags mirror the
 * capability state Stripe reports on that account and are refreshed from the
 * `account.updated` webhook — they are the gate the donation flow checks before
 * creating a charge on the org's behalf. Default false = not yet onboarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->string('stripe_account_id')->nullable()->after('google_maps_key');
            $table->boolean('stripe_charges_enabled')->default(false)->after('stripe_account_id');
            $table->boolean('stripe_payouts_enabled')->default(false)->after('stripe_charges_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_account_id',
                'stripe_charges_enabled',
                'stripe_payouts_enabled',
            ]);
        });
    }
};
