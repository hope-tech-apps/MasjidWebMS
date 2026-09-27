<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apex↔www canonical redirects (Manara Studio W2, S5; plan R9).
 *
 * A client with both `example.org` and `www.example.org` serves one of them
 * (`www`, owner decision 2026-09-24) and redirects the other with a Cloudflare
 * Single Redirect rule, so only the serving host is a custom domain on the
 * renderer's Pages project and the client uses one of its slots, not two.
 *
 *  - `role`: `serving` (every existing row, and the default) or `redirect`. A
 *    redirect host is in neither the lookup's nor CORS's scope.
 *  - `redirect_to_id`: the serving row a redirect row points at. Nulled if
 *    that row goes.
 *  - `cf_redirect_rule_id`: the rule Studio added to the zone's
 *    `http_request_dynamic_redirect` entry point, so detach can remove it.
 *
 * A self-referencing foreign key makes SQLite rebuild the table; that drops a
 * PARTIAL index, and this table has none (its two indexes are plain and
 * MasjidDomainSchemaTest pins both by name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_domains', function (Blueprint $table) {
            if (! Schema::hasColumn('masjid_domains', 'role')) {
                $table->string('role', 16)->default('serving')->after('kind');
            }

            if (! Schema::hasColumn('masjid_domains', 'cf_redirect_rule_id')) {
                $table->string('cf_redirect_rule_id', 64)->nullable()->after('cf_pages_domain_id');
            }
        });

        if (! Schema::hasColumn('masjid_domains', 'redirect_to_id')) {
            Schema::table('masjid_domains', function (Blueprint $table) {
                $table->foreignId('redirect_to_id')->nullable()->after('role')
                    ->constrained('masjid_domains', 'id', 'md_redirect_to_fk')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('masjid_domains', 'redirect_to_id')) {
            Schema::table('masjid_domains', function (Blueprint $table) {
                $table->dropForeign('md_redirect_to_fk');
                $table->dropColumn('redirect_to_id');
            });
        }

        foreach (['cf_redirect_rule_id', 'role'] as $column) {
            if (Schema::hasColumn('masjid_domains', $column)) {
                Schema::table('masjid_domains', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
