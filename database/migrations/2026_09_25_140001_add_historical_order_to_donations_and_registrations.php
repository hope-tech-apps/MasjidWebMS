<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point an imported donation or registration at the order it came from.
 *
 * Set only by `crm:import-wix-orders`, together with `source = 'historical'`
 * (DECISIONS.md 2026-09-25, "Wix order history"). Null on every row that exists
 * today, so every existing gift and registration reads as "not imported".
 *
 * No `->constrained()`: adding a foreign key to an existing table rebuilds it on
 * SQLite and drops partial indexes (.claude/rules/migrations.md). The importer's
 * undo deletes these rows before their order, so nothing is left dangling.
 * Index names are written by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->unsignedBigInteger('historical_order_id')->nullable();
            $table->index('historical_order_id', 'donations_historical_order_index');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->unsignedBigInteger('historical_order_id')->nullable();
            $table->index('historical_order_id', 'registrations_historical_order_index');
        });
    }

    public function down(): void
    {
        // The index first: SQLite refuses to drop a column an index still names.
        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex('donations_historical_order_index');
        });
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn('historical_order_id');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropIndex('registrations_historical_order_index');
        });
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn('historical_order_id');
        });
    }
};
