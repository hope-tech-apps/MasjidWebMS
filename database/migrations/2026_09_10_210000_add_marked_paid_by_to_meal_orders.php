<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who marked a pay-at-pickup order paid. With cash changing hands at the table,
 * "who took this money?" is the question the office asks afterwards, and until
 * now Mark paid recorded no one. A plain indexed column, no foreign key: adding
 * one to an existing table rebuilds it on SQLite and drops partial indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('marked_paid_by_user_id')->nullable()->after('paid_at');
            $table->index('marked_paid_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->dropIndex(['marked_paid_by_user_id']);
            $table->dropColumn('marked_paid_by_user_id');
        });
    }
};
