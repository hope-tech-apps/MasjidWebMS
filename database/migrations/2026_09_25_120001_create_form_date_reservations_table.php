<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A date a form response has reserved from its form's list (Ramadan giving through
 * forms, 2026-09-25): an iftar sponsorship that takes one evening of Ramadan.
 *
 * TWO PAYERS CAN NEVER HOLD THE SAME DATE, and that is enforced by the database,
 * not only by the check before the insert:
 *
 *   reserved_on  the date asked for, kept for ever (the record of what was asked)
 *   holding_on   the same date while the reservation still counts, NULL once it has
 *                been released. unique(form_id, holding_on) is the guarantee: NULLs
 *                are distinct in a unique index on MySQL and SQLite alike, so any
 *                number of released rows sit beside the one row holding a date
 *                (.claude/rules/migrations.md, "a CONDITIONAL unique index").
 *                A plain nullable column the app writes, not a generated one, so the
 *                two drivers have the same schema.
 *
 * The submit takes the form's row lock before it looks, so two submissions for one
 * date queue behind each other; the index is what still holds if anything writes
 * around that lock.
 *
 *   held_until   when an UNPAID card registration's hold lapses: its payment page's
 *                life plus a grace (App\Support\FormReservations). NULL never lapses:
 *                paid, cash, or a family paying the office.
 *   released_at / release_reason  when and why holding_on was cleared: `lapsed`
 *                (never paid, and another payer asked for the date) or `cancelled`
 *                (an admin cancelled the registration, and another payer asked).
 *
 * A hold is released LAZILY, by the next payer who asks for that date, never by a
 * clock: until then a late payment on it still finds its date. Deleting a response
 * deletes its reservation (cascade), which frees the date.
 *
 * Tenant-scoped by masjid_id (BelongsToMasjid on App\Models\FormDateReservation).
 * Index names are written by hand: MySQL caps identifiers at 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_date_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->onDelete('cascade');
            $table->foreignId('form_id')->constrained('forms')->onDelete('cascade');
            $table->foreignId('form_response_id')->constrained('form_responses')->onDelete('cascade');
            $table->date('reserved_on');
            $table->date('holding_on')->nullable();
            $table->dateTime('held_until')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 20)->nullable();
            $table->timestamps();

            $table->unique(['form_id', 'holding_on'], 'form_date_resv_form_holding_unique');
            $table->index(['masjid_id', 'form_id', 'reserved_on'], 'form_date_resv_masjid_form_date_idx');
            $table->index('form_response_id', 'form_date_resv_response_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_date_reservations');
    }
};
