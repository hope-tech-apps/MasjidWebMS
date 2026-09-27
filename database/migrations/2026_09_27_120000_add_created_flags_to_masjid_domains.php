<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a host's Cloudflare objects Studio itself made (Manara Studio W2, S3).
 *
 * Detaching a host sends Cloudflare's first DELETEs, and the Studio token can
 * edit every zone in the account, the live tenants' included. So a delete is
 * allowed only for an object Studio's own POST created, and these flags are
 * where that is recorded, as `cf_zone_created` already records it for a zone.
 * An ADOPTED object (one that already existed, which W1 stores by id exactly
 * as it stores a created one) keeps its flag false and is never deleted.
 *
 *  - `cf_dns_record_created`: the row's `cf_dns_record_id` is a record Studio's
 *    POST made.
 *  - `cf_pages_domain_created`: the row's `cf_pages_domain_id` is a Pages
 *    custom domain Studio's POST added.
 *  - `adopted_from_import_at`: set by S6's `domains:imported adopt`, when an
 *    imported row is handed to the attacher. Such a row keeps an imported
 *    row's protections for its life: never detached, never auto-demoted.
 *
 * Every row that exists when this runs keeps both flags false, so nothing
 * attached before this slice can ever be deleted by it; its removal stays the
 * manual steps MasjidDomain::removalSteps() gives. Additive, with defaults, on
 * a small table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_domains', function (Blueprint $table) {
            if (! Schema::hasColumn('masjid_domains', 'cf_dns_record_created')) {
                $table->boolean('cf_dns_record_created')->default(false)->after('cf_zone_created');
            }

            if (! Schema::hasColumn('masjid_domains', 'cf_pages_domain_created')) {
                $table->boolean('cf_pages_domain_created')->default(false)->after('cf_dns_record_created');
            }

            if (! Schema::hasColumn('masjid_domains', 'adopted_from_import_at')) {
                $table->timestamp('adopted_from_import_at')->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        foreach (['adopted_from_import_at', 'cf_pages_domain_created', 'cf_dns_record_created'] as $column) {
            if (Schema::hasColumn('masjid_domains', $column)) {
                Schema::table('masjid_domains', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
