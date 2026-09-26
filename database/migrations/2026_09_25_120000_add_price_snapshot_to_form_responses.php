<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The price breakdown a paying response was written at: what one unit cost, how
 * many units, and which price it was (Ramadan giving through forms, 2026-09-25).
 *
 * `amount_due_minor` already says what is owed. It does not say WHY: Zakat-ul-Fitr
 * for four people at $17 and one iftar sponsorship at $68 both owe 6800. The
 * receipt and the admin view restate the breakdown, and they must restate the one
 * the payer was quoted, not one recomputed later from a form whose prices or
 * questions an admin has since edited. So it is snapshotted here, at submit, from
 * the same FormPayment::quote() that writes amount_due_minor, and
 * unit_price_minor x price_quantity = amount_due_minor on every row that has them.
 *
 *   unit_price_minor  one unit in integer cents (never a float)
 *   price_quantity    the units charged: people, entries, 1 for a flat or tier price
 *   price_label       the tier or choice the unit price is ("Quarter Iftar",
 *                     "Early bird"), or null. Its length is decided by the form
 *                     schema's option labels, so it is truncated to fit on write
 *
 * All nullable: every row written before this, and every row on a form that takes
 * no payment, has no breakdown and shows none. Not fillable (FormResponse), like
 * every other money column. Names avoid the staging scrub's PII tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->unsignedInteger('unit_price_minor')->nullable();
            $table->unsignedInteger('price_quantity')->nullable();
            $table->string('price_label', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropColumn(['unit_price_minor', 'price_quantity', 'price_label']);
        });
    }
};
