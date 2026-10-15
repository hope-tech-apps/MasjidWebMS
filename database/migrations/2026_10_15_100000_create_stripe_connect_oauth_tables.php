<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_connect_oauth_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nonce_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('stripe_connect_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_last4', 4);
            $table->string('action', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_connect_histories');
        Schema::dropIfExists('stripe_connect_oauth_states');
    }
};
