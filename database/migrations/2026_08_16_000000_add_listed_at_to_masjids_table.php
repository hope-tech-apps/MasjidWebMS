<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `masjids.listed_at` — the public DIRECTORY LISTING gate.
 *
 * ## What it gates
 *
 * `Mobile\MasjidsController@index` (GET /mobile/masjids) is the organisation
 * picker every mobile app opens with. It returned `Masjid::with('logo')->get()`
 * — EVERY row, no gate, cached for a day — so creating a tenant published it to
 * every app user within 24 hours whether or not anyone was ready. That is also
 * why the `test2` seed tenant is currently offered to real users.
 *
 * `listed_at` is that gate: NULL means "not in the directory", a timestamp means
 * "listed, since then". New organisations are born NULL, so the wizard no longer
 * publishes on create; a SuperAdmin lists deliberately
 * (PATCH /api/admin/masjids/{id}/directory-listing).
 *
 * ## Why a column on `masjids` and not `masjid_app_publishing`
 *
 * `masjid_app_publishing` was the obvious candidate — it is 1:1 with a masjid
 * and the onboarding wizard creates it. It cannot carry this:
 *
 *  1. It does not exist for the tenants that matter. Measured on production
 *     2026-08-12, exactly ONE of the four tenants has a row (masjid 13); 1, 2
 *     and 5 have none, because the table was created 2026-07-23 and they predate
 *     it. A gate anchored there has to answer "no row" somehow, and both answers
 *     are wrong: "no row ⇒ unlisted" hides Burlington Masjid (221 mobile app
 *     users) — a production incident — and "no row ⇒ listed" is a fail-OPEN
 *     default, the exact shape of the two cross-tenant holes found live on
 *     2026-08-11.
 *  2. It answers a different question. Every column there is an account mode or
 *     an encrypted publishing credential, all `$hidden` on the model. Whether an
 *     organisation is discoverable is a product state of the ORGANISATION, not a
 *     property of how its binaries are signed. `enabled_platforms` says which
 *     builds get produced — a web-only organisation still belongs in the
 *     directory, and an organisation with an iOS build configured may still not
 *     be ready to be seen.
 *
 * `crm_enabled` is a per-tenant feature gate for the member directory + money
 * path and is deliberately NOT conscripted for this.
 *
 * ## The backfill, and why it preserves today's behaviour
 *
 * The column defaults to NULL, so a plain `ALTER` would hide all four live
 * tenants the moment it ran. Existing rows are therefore backfilled by POLICY —
 * never by id — to `listed_at = created_at`:
 *
 *   an organisation that real people are ALREADY using, or that has already
 *   published content to them.
 *
 * Implemented as three EXISTS clauses (mobile app users / announcements /
 * services), OR-ed, so the predicate errs toward listing: any single sign of
 * real-world use keeps an organisation visible.
 *
 * Verified against production before it was written (2026-08-12):
 *
 *   id  name                      app users  announcements  services  ⇒ listed
 *    1  Burlington Masjid               221             12        10     yes
 *    5  NAFIS Apex Mosque                 1              0         8     yes
 *   13  Muslim Education Center           3              3        11     yes
 *    2  test2                             0              0         0     NO
 *
 * So the three real congregations keep the listing they have today, and the seed
 * tenant drops out because it has no audience and has published nothing — not
 * because its id is written down here.
 *
 * Soft-deleted rows are deliberately NOT excluded: they are already invisible
 * through the SoftDeletes global scope, and backfilling them keeps `restore()`
 * meaning what it meant before this migration.
 *
 * Re-runnable on purpose (`hasColumn` guard + `whereNull`), so
 * MasjidDirectoryListingBackfillTest can seed a stand-in for today's production
 * rows and then migrate forward over it — the assertion this migration most
 * needed and could not otherwise make.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('masjids', 'listed_at')) {
            Schema::table('masjids', function (Blueprint $table) {
                // Nullable timestamp rather than a boolean: it records WHEN an
                // organisation went live, which is the question asked when one
                // turns up in the directory unexpectedly.
                $table->timestamp('listed_at')->nullable()->after('org_type')->index();
            });
        }

        // Backfill by policy. `whereNull` means this only ever FILLS IN a
        // listing, never moves one that already exists — which is what makes the
        // replay in MasjidDirectoryListingBackfillTest legitimate. (Laravel runs
        // a migration once per database, so a second pass is a test affordance,
        // not a deploy path.)
        DB::table('masjids')
            ->whereNull('listed_at')
            ->where(function ($query) {
                foreach (['mobile_app_users', 'announcements', 'services'] as $i => $table) {
                    $exists = function ($sub) use ($table) {
                        $sub->from($table)
                            ->selectRaw('1')
                            ->whereColumn($table . '.masjid_id', 'masjids.id');
                    };

                    $i === 0 ? $query->whereExists($exists) : $query->orWhereExists($exists);
                }
            })
            // Bare column name — valid on both MySQL and SQLite, and the honest
            // answer to "listed since when" for a tenant that was always listed.
            ->update(['listed_at' => DB::raw('created_at')]);

        // The directory is cached for a day (MobileCache::TTL_DAY), so without
        // this the newly-unlisted tenant keeps being served until the entry
        // expires. Best-effort: a cache store that isn't reachable during a
        // migration must not abort the deploy.
        try {
            \App\Support\MobileCache::flushGlobal(\App\Support\MobileCache::MASJIDS_LIST);
        } catch (\Throwable $e) {
            // Ignored on purpose — the entry expires within TTL_DAY regardless.
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('masjids', 'listed_at')) {
            return;
        }

        Schema::table('masjids', function (Blueprint $table) {
            $table->dropIndex(['listed_at']);
            $table->dropColumn('listed_at');
        });
    }
};
