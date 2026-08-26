<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table Laravel's password broker has always been pointed at, and which
 * this application never created.
 *
 * config/auth.php:210-215 configures the `users` broker against
 * `password_reset_tokens` with a 60-minute expiry — the framework default,
 * untouched. The table was simply never migrated, so every reset path would
 * have died on a missing table. Nobody noticed because there were no reset
 * routes either: staff accounts are created by a SuperAdmin who types a
 * password, and there was no way to recover one.
 *
 * That is fine for three people who know each other. It stops being fine the
 * moment a SCHOOL runs its own portal: Al-Razi's staff cannot be handed a
 * password over the phone and left with no recovery, and nobody should be
 * choosing another organisation's credentials for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('password_reset_tokens')) {
            return;
        }

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
