<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the paper-check number for rent payments and offline donations made
 * by check, so admins can reconcile against their bank statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rent_payments', function (Blueprint $table) {
            $table->string('check_number', 50)->nullable()->after('payment_method');
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->string('check_number', 50)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('rent_payments', function (Blueprint $table) {
            $table->dropColumn('check_number');
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn('check_number');
        });
    }
};
