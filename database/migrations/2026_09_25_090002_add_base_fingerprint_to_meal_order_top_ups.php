<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the order looked like when its top-up was priced (review fix, 2026-09-25).
 *
 * `base_total_minor` and `base_settled_minor` catch a staff edit that moves the
 * money, but not one that keeps it: staff swapping a dish for another at the same
 * price leaves both where they were, and the webhook would then write the
 * customer's older basket over the staff change. `base_fingerprint` is
 * MealOrderEditor::fingerprint() of the order at that moment (every line and every
 * money column, sha256), and the webhook applies the top-up only to an order that
 * still has it; anything else is a conflict, the money recorded and the plates
 * not.
 *
 * Nullable only because a column cannot be added NOT NULL without a default; every
 * top-up opened since this migration carries one, and a row without one is never
 * applied. Not personal data. No index: read with the row, never filtered on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_order_top_ups', function (Blueprint $table) {
            $table->char('base_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meal_order_top_ups', function (Blueprint $table) {
            $table->dropColumn('base_fingerprint');
        });
    }
};
