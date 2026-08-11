<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * appointment_request_notes — internal staff notes on one appointment request
 * (PLAN T-021). "Called back, no answer", "needs an interpreter" — working
 * notes about a person's health contact, so `body` is an `encrypted` cast on
 * App\Models\AppointmentRequestNote (TEXT for the ciphertext), and the notes
 * ride only on the admin `show` endpoint, never any public payload.
 *
 * masjid_id is denormalised (same reasoning as group_memberships): note queries
 * scope through BelongsToMasjid without joining through appointment_requests,
 * and the cross-tenant guardrail holds on this table in its own right.
 *
 * The FK cascade to appointment_requests is a BACKSTOP only — deletion flows
 * through AppointmentRequest's `deleting` hook so model events fire (a DB
 * cascade fires none; see .claude/rules/private-uploads.md for the pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_request_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_request_id')->constrained()->cascadeOnDelete();

            // The staff author. Nullable + nullOnDelete on purpose: the note is
            // the CLINIC'S record of the contact, not the author's — deleting a
            // staff account must not silently destroy the request's history.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Encrypted at rest — see the table docblock.
            $table->text('body');

            $table->timestamps();

            // The only read is "this request's notes" under the tenant scope.
            $table->index(['masjid_id', 'appointment_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_request_notes');
    }
};
