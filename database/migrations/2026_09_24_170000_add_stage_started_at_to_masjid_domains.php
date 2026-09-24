<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a `masjid_domains` row entered the status it is waiting in (Manara
 * Studio W1, S7).
 *
 * The attacher gives up on two waits: a zone still `awaiting_nameservers` after
 * 28 days (Cloudflare deletes a Free-plan zone left pending that long) and a
 * Pages certificate still not issued 72 hours after the custom domain was
 * added. Both clocks have to start when the row reached that status. The row's
 * `created_at` cannot stand in: a host added while CLOUDFLARE_STUDIO_TOKEN was
 * absent can sit `pending` for weeks, and measured from its creation it would
 * be failed on the first tick after the token lands. `updated_at` moves on every
 * check. So the attacher stamps this column on each move into a waiting status
 * and clears it on the way out.
 *
 * Additive and nullable on a table that is empty on production today; no
 * index, because the reconciler selects on `status` and `next_check_at`, which
 * are indexed already.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('masjid_domains', 'stage_started_at')) {
            return;
        }

        Schema::table('masjid_domains', function (Blueprint $table) {
            $table->timestamp('stage_started_at')->nullable()->after('next_check_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('masjid_domains', 'stage_started_at')) {
            return;
        }

        Schema::table('masjid_domains', function (Blueprint $table) {
            $table->dropColumn('stage_started_at');
        });
    }
};
