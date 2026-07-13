<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * donation_receipts — the official tax receipt for a succeeded donation.
 * Tenant-scoped by masjid_id (BelongsToMasjid).
 *
 * `serial_number` is a GAP-FREE per-masjid counter (1, 2, 3, …) allocated
 * transaction-safely by App\Services\Receipts\ReceiptService — regulators
 * expect receipt sequences with no holes. The unique (masjid_id, serial_number)
 * is the hard backstop against a duplicate or skipped serial.
 *
 * Amounts are integer minor units. `eligible_amount = gross_amount −
 * advantage_amount` (advantage is 0 for a plain cash gift with nothing of value
 * received in return). `jurisdiction` defaults to 'US' for this spike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('donation_id')->constrained();

            // Gap-free per-masjid sequence (see ReceiptService).
            $table->unsignedBigInteger('serial_number');
            $table->date('issue_date');

            // Minor units.
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('advantage_amount')->default(0);
            $table->unsignedBigInteger('eligible_amount');

            $table->char('currency', 3)->default('usd');
            $table->string('jurisdiction')->default('US');
            $table->enum('status', ['issued', 'void'])->default('issued');

            $table->timestamps();

            // Hard backstop: the serial is unique WITHIN a masjid.
            $table->unique(['masjid_id', 'serial_number']);
            // One receipt per donation (idempotent issuance).
            $table->unique('donation_id');
            $table->index(['masjid_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_receipts');
    }
};
