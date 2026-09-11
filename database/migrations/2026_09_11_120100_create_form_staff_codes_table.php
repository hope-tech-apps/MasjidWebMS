<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One secret code per staff member per form, for taking cash at the gate
 * (DECISIONS.md 2026-09-11). A valid code settles a walk-up's registration as
 * cash held by that person, so "who took the money?" becomes a GROUP BY.
 *
 * The row is the RECORD of a credential, never the credential:
 *
 *   code_hash   hash_hmac('sha256', normalised code, APP_KEY) — keyed, the
 *               ContactLoginCode / FamilyLoginService pattern. A bare sha256 of
 *               eight characters is reversible by anyone holding a copy of the
 *               table. The plaintext is shown once, in the admin response that
 *               created it, and exists nowhere after.
 *   code_hint   the last two characters, so an admin can tell "the 7K code" from
 *               the others without the secret.
 *
 * Per FORM, not per masjid: a leaked code can only register people against its
 * holder's cash total on one event. (form_id, code_hash) is unique — the lookup
 * index and the collision guard in one.
 *
 * expires_at is NOT NULL. DECISIONS.md requires every code to expire after the
 * event, so a code that never expires is not representable; the admin side
 * stores an explicit instant (the end of the event day in the masjid's
 * timezone).
 *
 * bound_device_id: the first device that uses a code owns it, so a code read
 * over someone's shoulder is useless on a second phone; an admin can clear it.
 * The same width as form_responses.device_id, which it is compared against.
 * Clearing it is how a code comes to be used from a phone that is not its
 * holder's, so it leaves a trail: binding_count (every claim) and
 * binding_released_at / binding_released_by_user_id (the last release). Named
 * binding_*, not device_*: none of them identifies anyone's phone.
 *
 * No soft deletes. revoked_at is the lifecycle, and the rows stay for ever as
 * the reconciliation record that form_responses.staff_code_id points at.
 *
 * A NEW table, so `->constrained()` is safe here — the SQLite rebuild trap is
 * about adding a foreign key to an EXISTING table. Index names are by hand and
 * well under MySQL's 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_staff_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();

            // As typed by an admin. The 120 is enforced by the request as well:
            // SQLite would store 300 characters here without complaint.
            $table->string('holder_name', 120);

            $table->char('code_hash', 64);
            $table->string('code_hint', 4);

            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->string('bound_device_id')->nullable();
            $table->dateTime('bound_at')->nullable();

            $table->unsignedInteger('binding_count')->default(0);
            $table->dateTime('binding_released_at')->nullable();
            $table->unsignedBigInteger('binding_released_by_user_id')->nullable();

            $table->unsignedInteger('use_count')->default(0);
            $table->dateTime('last_used_at')->nullable();

            $table->timestamps();

            $table->unique(['form_id', 'code_hash'], 'form_staff_codes_form_hash_unique');
            $table->index(['masjid_id', 'form_id'], 'form_staff_codes_masjid_form_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_staff_codes');
    }
};
