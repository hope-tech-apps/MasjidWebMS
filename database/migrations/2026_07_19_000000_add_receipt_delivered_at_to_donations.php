<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when a donation's receipt email was delivered, so the (idempotent,
 * possibly re-delivered / out-of-order) webhook never emails the same receipt
 * twice. Null = not yet emailed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->timestamp('receipt_delivered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn('receipt_delivered_at');
        });
    }
};
