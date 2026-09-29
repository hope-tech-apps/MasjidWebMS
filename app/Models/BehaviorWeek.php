<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One points week of one class, as far as the Friday report is concerned (T-003.3).
 *
 * THE ROW IS THE SEND CLAIM, and it is only ever written by the
 * `points:weekly-report` command, through the query builder, never `create()`:
 * the command inserts the row (ignoring a duplicate) and then flips
 * `report_sent_at` from NULL with a conditional UPDATE, so exactly one process can
 * win the week (`BehaviorWeek::claim()`). `week_start` is the points week's first
 * local day as a plain 'Y-m-d' string, so SQLite and MySQL hold the same value and
 * the (group, week) unique index means the same thing on both.
 *
 * It carries no data about a child: a date, a timestamp and a count. So there is
 * nothing to retain, erase or scrub, and it can never be a leak. Tenant-scoped
 * like everything with a masjid_id (BelongsToMasjid); its cross-tenant test is in
 * tests/Feature/WeeklyPointsReportTest.php.
 */
class BehaviorWeek extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'group_id',
        'week_start',
        'report_sent_at',
        'recipients_count',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'report_sent_at' => 'datetime',
            'recipients_count' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Try to become the sender of this class's report for this week.
     *
     * True for exactly ONE caller per (group, week): the one whose conditional
     * UPDATE changed the row. The insert ignores a duplicate (the unique index), so
     * the loser of a race, a second server and a retried run all fall through to the
     * UPDATE and find it already taken. It is the database, not this method, that
     * decides: no read-then-write gap for two runs to slip through.
     *
     * `$masjidId` is passed explicitly: the command runs with no tenant bound, where
     * the creating hook would stamp nothing.
     */
    public static function claim(int $masjidId, int $groupId, string $weekStart): bool
    {
        $now = now();

        DB::table('behavior_weeks')->insertOrIgnore([
            'masjid_id' => $masjidId,
            'group_id' => $groupId,
            'week_start' => $weekStart,
            'report_sent_at' => null,
            'recipients_count' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('behavior_weeks')
            ->where('group_id', $groupId)
            ->where('week_start', $weekStart)
            ->whereNull('report_sent_at')
            ->update(['report_sent_at' => $now, 'updated_at' => $now]) === 1;
    }

    /** Has this class's report for this week already been claimed? */
    public static function sent(int $groupId, string $weekStart): bool
    {
        return DB::table('behavior_weeks')
            ->where('group_id', $groupId)
            ->where('week_start', $weekStart)
            ->whereNotNull('report_sent_at')
            ->exists();
    }
}
