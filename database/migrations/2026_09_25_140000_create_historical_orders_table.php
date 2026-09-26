<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders another system took, imported as history (`crm:import-wix-orders`).
 *
 * MEC ran its store and its event tickets on Wix from 2017 to 2026, paid through
 * Square and PayPal. The owner asked for every one of those orders in Manara's
 * donation and registration history, marked so that nothing reads as a payment
 * Manara took (DECISIONS.md 2026-09-25, "Wix order history").
 *
 * historical_orders — ONE ROW PER IMPORTED ORDER, for three jobs at once:
 *
 *   1. The idempotency map. (masjid_id, source, order_number) is unique, so a
 *      second run finds the order and writes nothing.
 *   2. Provenance. `provider` is the processor that took the money (square,
 *      paypal) or `wix` when the export does not say which, and
 *      `order_number` is the number the buyer and MEC's books know it by.
 *      Donations and registrations point back here through
 *      `historical_order_id` (the next migration).
 *   3. The honest home for what fits neither ledger: festival food tickets and
 *      a 2021 prayer-rug drive were purchases, not gifts and not seats. Their
 *      lines stay here (`lines`, each marked with what it was recorded as) and
 *      nowhere else, so they are kept without being dressed up as a donation.
 *
 *   No buyer name, email or address is stored: `contact_id` is the link, and the
 *   Wix checkout answers (emergency contacts on the 2024 zoo trip) are not
 *   carried at all.
 *
 * historical_import_records — THE UNDO LIST for the scaffolding a batch had to
 * create around the orders: contacts, funds, the shared intake form, offerings,
 * fee plans and email holds. Orders, donations and registrations are found by
 * batch and by `historical_order_id`; everything else is found here, so
 * `--undo` removes exactly what the batch created. `record_updated_at` is the
 * row's own updated_at when the import finished with it: a later difference
 * means somebody has built on the row since, and undo keeps it.
 *
 * Index names are written by hand (MySQL caps an identifier at 64 characters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // wix_stores | wix_events
            $table->string('source', 24);
            $table->string('order_number', 64);

            // square | paypal | wix (paid at the Wix checkout, processor not recorded)
            $table->string('provider', 16);
            // card | paypal, when the export says; null when it does not
            $table->string('payment_method', 32)->nullable();
            // paid | canceled | declined
            $table->string('status', 16);

            $table->dateTime('ordered_at');
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            // Integer minor units. total = the order's total as Wix priced it (after
            // the discount, including any fee Wix added at checkout) — money that
            // moved only when status is `paid`; an abandoned checkout keeps the
            // total it was never paid. fee_minor is that added fee, recorded for
            // reconciliation and never added again.
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->char('currency', 3)->default('usd');

            $table->json('lines');
            $table->string('import_batch', 64);

            $table->timestamps();

            $table->unique(['masjid_id', 'source', 'order_number'], 'hist_orders_tenant_source_number_unique');
            $table->index(['masjid_id', 'import_batch'], 'hist_orders_tenant_batch_index');
        });

        Schema::create('historical_import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->string('import_batch', 64);

            // contact | fund | form | offering | fee_plan | email_hold
            $table->string('record_type', 32);
            $table->unsignedBigInteger('record_id');
            $table->timestamp('record_updated_at')->nullable();

            $table->timestamps();

            $table->unique(['record_type', 'record_id'], 'hist_import_records_record_unique');
            $table->index(['masjid_id', 'import_batch'], 'hist_import_records_tenant_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_import_records');
        Schema::dropIfExists('historical_orders');
    }
};
