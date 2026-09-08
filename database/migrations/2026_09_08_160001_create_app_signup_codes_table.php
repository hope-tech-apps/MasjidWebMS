<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in codes for an address that may not be anybody yet.
 *
 * `contact_login_codes` cannot carry these: its `contact_id` is a non-nullable
 * constrained FK, and the whole point of app sign-up is that the first code
 * goes to an address with no contact behind it. Widening that column to
 * nullable would weaken a shipped auth table for the sake of a newer, less
 * trusted flow, so this is a separate table and `contact_login_codes` is left
 * exactly as the family realm built it.
 *
 * NOTHING HERE CREATES A CONTACT. A code is issued against an email string; the
 * `contacts` row is created (or matched) only when a code is redeemed, inside
 * the same transaction that burns it. So spraying this endpoint with a
 * dictionary of addresses writes rows here and never pollutes the CRM.
 *
 * Column-for-column the same shape as `contact_login_codes` — hashed digest,
 * channel, expiry, attempt counter, requester IP — because the redeem path
 * enforces the same rules and the two should not drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_signup_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Stored already lower-cased by the service. Deliberately NOT unique
            // with masjid_id: a member who requests twice must not have their
            // first code invalidated by their second, exactly as
            // FamilyLoginService documents for the family realm.
            $table->string('email');

            $table->char('code_hash', 64);
            $table->string('channel', 32);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('requested_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['masjid_id', 'email', 'expires_at'], 'app_signup_codes_lookup_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_signup_codes');
    }
};
