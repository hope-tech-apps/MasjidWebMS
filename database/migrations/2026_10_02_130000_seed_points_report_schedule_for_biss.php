<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Give Burlington Islamic Sunday School (org 18) its report schedule: Sunday 18:00
 * (owner, 2026-09-28: "BISS (Sundays only) gets it Sunday evening"; T-003.3).
 *
 * The default for every other school is Friday 15:00, which is what Al-Razi wants,
 * so Al-Razi needs no row. BISS meets on Sundays only, so a Friday report would
 * arrive five days after the last award; its week starts on the Sunday it meets, and
 * a Sunday 18:00 report covers that day up to that moment.
 *
 * INERT UNTIL SOMEONE SWITCHES THE REPORT ON. The row only says when; the
 * `points_weekly_report` capability is OFF for every organisation, BISS included, and
 * only a SuperAdmin can turn it on. So this changes nothing anybody receives.
 *
 * A DATA migration in the shape of 2026_09_21_120000 (BISS's other settings), with
 * the same guard: row 18 must be a live SCHOOL whose name says "Sunday School"
 * (a fresh database, a test run, and any environment where 18 is another
 * organisation get nothing and one warning). Idempotent: an existing row for the
 * school is a SuperAdmin's decision and is left alone. down() removes only a row
 * that still holds exactly what up() wrote AND has not been saved since
 * (`updated_at` still equal to `created_at`, the way the subjects seed reads
 * "untouched"), and says in one warning line what it removed. It cannot go
 * further without a mark on the row, which is a change to up() and to the table
 * (an applied migration is never edited): a row a SuperAdmin CREATED with these very
 * values and never touched still reads as ours. That is the gap this leaves.
 *
 * Query builder only, no raw SQL, so no driver guard.
 */
return new class extends Migration
{
    private const MASJID_ID = 18;
    private const WEEKDAY = 0;
    private const TIME = '18:00';

    public function up(): void
    {
        if (! $this->isBiss()) {
            return;
        }

        if (DB::table('masjid_points_settings')->where('masjid_id', self::MASJID_ID)->exists()) {
            return;
        }

        // ONE instant for both stamps: two now() calls can straddle a second, and down() reads
        // `updated_at != created_at` as "saved since", so it would skip the row it wrote.
        $now = now();

        DB::table('masjid_points_settings')->insert([
            'masjid_id' => self::MASJID_ID,
            'report_weekday' => self::WEEKDAY,
            'report_time' => self::TIME,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $removed = DB::table('masjid_points_settings')
            ->where('masjid_id', self::MASJID_ID)
            ->where('report_weekday', self::WEEKDAY)
            ->where('report_time', self::TIME)
            // A row saved since (a SuperAdmin put the same values back) is their decision, not ours.
            ->whereColumn('updated_at', 'created_at')
            ->delete();

        if ($removed > 0) {
            // Rolling this back returns BISS to the Friday 15:00 default; leave a line that says so.
            Log::warning('Points report schedule for the Sunday school removed by rollback', [
                'masjid_id' => self::MASJID_ID,
                'rows_removed' => $removed,
            ]);
        }
    }

    private function isBiss(): bool
    {
        $row = DB::table('masjids')->where('id', self::MASJID_ID)->first(['id', 'name', 'org_type', 'deleted_at']);

        $isBiss = $row !== null
            && $row->deleted_at === null
            && $row->org_type === 'school'
            && stripos((string) $row->name, 'sunday school') !== false;

        if (! $isBiss && $row !== null) {
            Log::warning('Points report schedule not seeded: organisation 18 is not the Sunday school', [
                'masjid_id' => self::MASJID_ID,
                'org_type' => $row->org_type,
            ]);
        }

        return $isBiss;
    }
};
