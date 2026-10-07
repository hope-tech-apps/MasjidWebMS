<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** No backfill: old sending rows have no reliable channel-start evidence. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->timestamp('sending_started_at')->nullable();
            $table->uuid('send_claim_token')->nullable();
            $table->timestamp('send_recovered_at')->nullable();
            $table->index(['status', 'sending_started_at'], 'broadcast_send_recovery_index');
        });
    }

    public function down(): void
    {
        // Dropping the ownership/recovery marker would reopen recovered failed
        // or partial sends on this dispatcher. Roll back code only after drain.
        if (\Illuminate\Support\Facades\DB::table('broadcasts')->whereNotNull('send_recovered_at')->exists()) {
            throw new RuntimeException('Cannot drop broadcast recovery evidence while recovered sends exist.');
        }
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropIndex('broadcast_send_recovery_index');
            $table->dropColumn(['sending_started_at', 'send_claim_token', 'send_recovered_at']);
        });
    }
};
