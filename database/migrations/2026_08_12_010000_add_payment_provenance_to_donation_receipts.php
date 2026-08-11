<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment provenance on the issued receipt (T-007b).
 *
 * An OFFLINE gift has no Stripe event behind it — a treasurer asserted that
 * $5,000 in cash or a cheque arrived. The receipt the donor files therefore has
 * to say what it is evidence of: "Received by: Cheque no. 1189", not a bare
 * amount that looks identical to a card charge.
 *
 *   - payment_method     how the gift arrived (cash/check/zelle/…), copied from
 *                        the donation AT ISSUANCE
 *   - payment_reference  the human-entered handle for it (cheque number today)
 *
 * SNAPSHOT, not a join. donation_receipts is the record of the document that was
 * handed over; every figure on the PDF is read off this row precisely so a later
 * edit or a re-import of the donation cannot silently change a receipt a donor is
 * already holding. Provenance is the field a human asserted, so it is the LAST
 * one that should be re-derived at render time.
 *
 * Both columns are NULLABLE and stay null for Stripe gifts (donations.payment_method
 * is an offline-only column — see 2026_07_22_100000_extend_donations_for_offline).
 * The online receipt row and the online receipt PDF are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_receipts', function (Blueprint $table) {
            $table->string('payment_method', 30)->nullable()->after('currency');
            $table->string('payment_reference', 50)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('donation_receipts', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_reference']);
        });
    }
};
