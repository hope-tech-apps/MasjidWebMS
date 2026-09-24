<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host -> organisation map as data (Manara Studio W1, S3).
 *
 * Until now the renderer knew which organisation a host belongs to only from
 * NUXT_TENANT_HOSTS, an environment variable on the Pages project, so adding a
 * client meant a redeploy. This table is where Studio records a host instead,
 * and where the live map is imported (domains:import-host-map). Nothing reads
 * it until S9 (CORS) and S11 (the renderer's lookup).
 *
 * `status`, `kind`, `source`, `waiting_on` and `verified_by` are plain strings
 * whose allowed values are constants on App\Models\MasjidDomain, not DB enums,
 * so a new status never needs an ALTER on a live table (the `org_type`
 * precedent). `last_error` is `text` because it holds Cloudflare's own words,
 * whose length we do not decide; MySQL would refuse a long one in a varchar and
 * SQLite would not notice (.claude/rules/shipping.md).
 *
 * The composite index is named by hand: MySQL caps identifiers at 64
 * characters and the generated name would be close to it; SQLite has no cap,
 * so the suite would never say.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('masjid_domains')) {
            return;
        }

        Schema::create('masjid_domains', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->string('host', 253)->unique();
            $table->string('kind', 32);
            $table->string('zone_apex', 253);
            $table->string('status', 32)->default('pending')->index();
            $table->string('waiting_on', 32)->nullable();
            $table->string('source', 32)->default('studio');
            $table->string('cf_zone_id', 64)->nullable();
            $table->string('cf_dns_record_id', 64)->nullable();
            $table->string('cf_pages_domain_id', 64)->nullable();
            $table->boolean('cf_zone_created')->default(false);
            $table->json('nameservers')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('serving_confirmed_at')->nullable();
            $table->string('verified_by', 16)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['masjid_id', 'status'], 'md_masjid_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masjid_domains');
    }
};
