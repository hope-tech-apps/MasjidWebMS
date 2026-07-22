<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline / manually-recorded giving.
 *
 * Until now every donation came from Stripe and was created by a webhook. Masjids
 * also take cash, checks, Zelle, Venmo, PayPal and Square, and have years of that
 * history in spreadsheets. These columns let a donation exist without Stripe:
 *
 *   - source        'stripe' (webhook-created, unchanged) or 'offline' (admin/import)
 *   - payment_method how an offline gift arrived (cash/check/zelle/…)
 *   - donated_at     the real gift date (offline history is dated to its month;
 *                    Stripe gifts leave this null and use created_at)
 *   - import_batch   tags rows created by a bulk import so the whole batch is
 *                    reversible in one query
 *   - note           free text (e.g. "2025 ledger, March")
 *
 * `type` is unchanged; an offline gift is type='one_time', source='offline'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('source', 20)->default('stripe')->after('type');
            $table->string('payment_method', 30)->nullable()->after('source');
            $table->date('donated_at')->nullable()->after('payment_method');
            $table->string('import_batch')->nullable()->after('idempotency_key');
            $table->text('note')->nullable()->after('import_batch');

            $table->index('source');
            $table->index('import_batch');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['import_batch']);
            $table->dropColumn(['source', 'payment_method', 'donated_at', 'import_batch', 'note']);
        });
    }
};
