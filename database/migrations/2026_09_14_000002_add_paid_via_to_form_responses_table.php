<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a hand-recorded form payment came in (BISS Sunday School registration,
 * 2026-09-13; DECISIONS.md).
 *
 * A family may now choose to pay the school office instead of a card
 * (settings.payment.officePayment). The office takes that money by Zelle, Cash App,
 * Venmo, cash or check, and its books have to say which. Settlement keeps writing
 * `payment_method` cash or external exactly as it always has, so every existing
 * reader of the method still works; this column is the detail beside it:
 *
 *   paid_via   'cash' | 'zelle' | 'cashapp' | 'venmo' | 'check'  (FormResponse::PAID_VIA)
 *              NULL on a card payment and on every row settled before today.
 *
 * NULLABLE with no default, so every existing row keeps meaning exactly what it
 * meant. No backfill: a payment recorded before the question existed was never
 * told how it came, and a guess is not a record.
 *
 * string(16), the precedent `payment_method` set in
 * 2026_09_11_120000_add_payment_columns_to_form_responses.php. SQLite enforces no
 * VARCHAR length, so FormOfficePaymentTest pins the declared type and length.
 *
 * A plain column: no foreign key (adding one to an existing table rebuilds it on
 * SQLite and drops partial indexes; .claude/rules/migrations.md) and no index
 * (nothing filters on it; the cash totals group it inside a query already narrowed
 * to one form). Blueprint only, identical on MySQL and SQLite; no `->after()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->string('paid_via', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropColumn('paid_via');
        });
    }
};
