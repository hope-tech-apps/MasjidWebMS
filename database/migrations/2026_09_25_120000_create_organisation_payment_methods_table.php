<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accepted payment methods (owner, 2026-09-21: "build a Manara-wide 'accepted
 * payment methods + how to pay' setting now").
 *
 * One row per method an organisation ACCEPTS. A method with no row is not
 * accepted; there is no is_enabled flag, because a switched-off method with
 * instructions still attached is exactly the text that ends up shown by mistake.
 *
 *  - `method` is one of App\Support\PaymentMethods::KEYS (card, cash, check,
 *    zelle, bank_transfer, other). A plain string, never a DB enum, so a new
 *    method needs no ALTER on a live table.
 *  - `label` is the organisation's own name for the method, used for `other`
 *    ("PayPal", "Square invoice") and as an optional override for the rest.
 *  - `instructions` is the organisation's "how to pay" text, shown to the public
 *    beside the method. `text`, because its length is the organisation's to
 *    decide and SQLite would never have told us a VARCHAR was too short
 *    (.claude/rules/shipping.md, "Column lengths"); a test asserts the type.
 *
 * The unique index is named by hand: Laravel's generated name for this table and
 * these two columns is 62 characters, too close to MySQL's 64 to leave alone.
 *
 * Nothing here is personal data. The instructions are an organisation's public
 * payment details (a Zelle address, a mailing address for checks), which the
 * organisation publishes on purpose; no column name matches the staging scrub's
 * PII tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->string('method', 24);
            $table->string('label', 64)->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['masjid_id', 'method'], 'org_pay_methods_masjid_method_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_payment_methods');
    }
};
