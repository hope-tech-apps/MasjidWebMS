<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language an organisation's WEBSITE renders in (Studio W2 S12, plan R11):
 * `en` or `ar` (Masjid::WEBSITE_LOCALES), null for "not chosen", which is
 * every existing organisation and renders exactly as today.
 *
 * On `masjids`, not `masjid_domains` or `theme_settings`: the starter labels and
 * the site's chrome must agree, and both are chosen per organisation, not per
 * host. Not `mailing_locale`, which is an address line. Additive and nullable,
 * so no live row changes; the by-host lookup emits `locale` only when it is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->string('website_locale', 8)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->dropColumn('website_locale');
        });
    }
};
