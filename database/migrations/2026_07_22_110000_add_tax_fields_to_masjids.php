<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Org details a 501(c)(3) tax letter needs but the masjid record didn't hold:
 *   - tax_id               EIN (e.g. 46-4693999) printed on receipts/statements
 *   - statement_signatory  who signs the letter (e.g. "Shaher Sayed Ahmad")
 *   - mailing_locale        the city/state/ZIP line under the street address
 *
 * Per-masjid so every tenant's letters carry their own registration + signer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->string('tax_id')->nullable()->after('website_link');
            $table->string('statement_signatory')->nullable()->after('tax_id');
            $table->string('mailing_locale')->nullable()->after('statement_signatory');
        });
    }

    public function down(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->dropColumn(['tax_id', 'statement_signatory', 'mailing_locale']);
        });
    }
};
