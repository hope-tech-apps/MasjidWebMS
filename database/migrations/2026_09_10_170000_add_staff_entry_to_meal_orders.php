<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-entered Jummah lunch orders: an order an admin or a lunch volunteer
 * takes at the table, over the phone, or for someone without the link.
 *
 * `source` tells the board which door an order came through ('online' for the
 * public page, 'staff' for the board), and `entered_by_user_id` who took it —
 * with cash changing hands at the table, "who entered this?" is the question
 * the office asks afterwards.
 *
 * A plain indexed column rather than `->constrained()`: adding a foreign key to
 * an existing table rebuilds it on SQLite and can drop partial indexes (see
 * .claude/rules/migrations.md). Existing rows default to 'online', which is
 * what every one of them was. Blueprint only — identical on MySQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->string('source', 16)->default('online')->after('payment_method');
            $table->unsignedBigInteger('entered_by_user_id')->nullable()->after('source');
            $table->index('entered_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->dropIndex(['entered_by_user_id']);
            $table->dropColumn(['source', 'entered_by_user_id']);
        });
    }
};
