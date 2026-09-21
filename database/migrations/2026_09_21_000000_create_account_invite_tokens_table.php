<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the 7-day first-password links for new staff accounts live, apart from
 * the 60-minute Forgot-password tokens in `password_reset_tokens`.
 *
 * Shaped exactly like that table because the same framework broker reads it
 * (config/auth.php `invites`). It is a separate table, rather than a longer
 * expiry on the shared one, because a token carries no record of why it was
 * minted: one table with two lifetimes cannot tell an invite from a reset, and
 * the only way to give invites longer is to give every reset longer too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('account_invite_tokens')) {
            return;
        }

        Schema::create('account_invite_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invite_tokens');
    }
};
