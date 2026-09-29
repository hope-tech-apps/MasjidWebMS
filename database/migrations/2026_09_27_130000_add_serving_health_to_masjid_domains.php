<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a confirmed host is still serving its organisation (Manara Studio
 * W2, S4).
 *
 * W1 confirmed a host once and never looked again, so a host that stopped
 * serving kept its CORS and card-payment-return admission forever. From S4 the
 * poller probes every confirmed host once a day, and these record what it saw:
 *
 *  - `serving_last_seen_at`: the last probe that matched;
 *  - `serving_missed_since`: the first miss of the current run of misses,
 *    cleared by a match;
 *  - `serving_miss_count`: how many probes in a row have missed.
 *
 * A Studio row is demoted (its `serving_confirmed_at` cleared) only after
 * three misses spanning at least 72 hours; an imported or adopted row never is
 * (plan R10). Additive, nullable or defaulted, on a small table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_domains', function (Blueprint $table) {
            if (! Schema::hasColumn('masjid_domains', 'serving_last_seen_at')) {
                $table->timestamp('serving_last_seen_at')->nullable()->after('serving_confirmed_at');
            }

            if (! Schema::hasColumn('masjid_domains', 'serving_missed_since')) {
                $table->timestamp('serving_missed_since')->nullable()->after('serving_last_seen_at');
            }

            if (! Schema::hasColumn('masjid_domains', 'serving_miss_count')) {
                $table->unsignedSmallInteger('serving_miss_count')->default(0)->after('serving_missed_since');
            }
        });
    }

    public function down(): void
    {
        foreach (['serving_miss_count', 'serving_missed_since', 'serving_last_seen_at'] as $column) {
            if (Schema::hasColumn('masjid_domains', $column)) {
                Schema::table('masjid_domains', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
