<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * One points week of one class, as far as the Friday report is concerned (T-003.3).
 *
 * THE ROW IS THE SEND CLAIM, and it is only ever written by the
 * `points:weekly-report` command, through the query builder, never `create()`:
 * the command inserts the row (a duplicate is the one error it ignores) and then flips
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
        // Manara Bucks (T-003.4): this class's week has been turned into bucks. A record and a
        // saving of work (bucks:mint skips a converted week older than its adjustment window);
        // the ledger's dedupe_key is what makes minting once-only.
        'prizes_converted_at',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'report_sent_at' => 'datetime',
            'recipients_count' => 'integer',
            'prizes_converted_at' => 'datetime',
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
     * UPDATE changed the row. The insert may hit the unique index, and ONLY that is
     * swallowed: the loser of a race, a second server and a retried run all fall
     * through to the UPDATE and find it already taken. It is the database, not this
     * method, that decides: no read-then-write gap for two runs to slip through.
     *
     * Any other failure of the insert (a class deleted between the read and the
     * claim, a full disk, a lost connection) is thrown. It used to be
     * `insertOrIgnore`, which on MySQL is INSERT IGNORE and turns those errors into
     * warnings: the row was not written, the UPDATE matched nothing, and the run
     * counted the class as "already sent" with no line to say otherwise
     * (review, optional fold).
     *
     * `$masjidId` is passed explicitly: the command runs with no tenant bound, where
     * the creating hook would stamp nothing.
     */
    public static function claim(int $masjidId, int $groupId, string $weekStart): bool
    {
        $now = now();

        try {
            DB::table('behavior_weeks')->insert([
                'masjid_id' => $masjidId,
                'group_id' => $groupId,
                'week_start' => $weekStart,
                'report_sent_at' => null,
                'recipients_count' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The (group, week) row exists: sent, released, or taken by a run that is racing
            // this one. The conditional UPDATE below says which.
        }

        return DB::table('behavior_weeks')
            ->where('group_id', $groupId)
            ->where('week_start', $weekStart)
            ->whereNull('report_sent_at')
            ->update(['report_sent_at' => $now, 'updated_at' => $now]) === 1;
    }

    /**
     * Give a claim back after a send in which NOT ONE mail went out, so the next run may
     * try the week again. The command calls this only on total failure; releasing after a
     * partial send would tell the families who already have the notice a second time.
     */
    public static function release(int $groupId, string $weekStart): void
    {
        DB::table('behavior_weeks')
            ->where('group_id', $groupId)
            ->where('week_start', $weekStart)
            ->update(['report_sent_at' => null, 'recipients_count' => null, 'updated_at' => now()]);
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

    /**
     * Has this class's week already been turned into Manara Bucks (T-003.4)? Independent of
     * the report's claim above: a row that exists only because minting made it has a null
     * `report_sent_at`, so `sent()` still says the report has not gone.
     */
    public static function prizesConverted(int $groupId, string $weekStart): bool
    {
        return DB::table('behavior_weeks')
            ->where('group_id', $groupId)
            ->where('week_start', $weekStart)
            ->whereNotNull('prizes_converted_at')
            ->exists();
    }

    /**
     * Record that minting has processed this class's week. An insert then a conditional
     * UPDATE, exactly the shape of claim(), so it never disturbs a row the report already owns
     * and never overwrites the first stamp.
     *
     * Only a duplicate of the (group, week) row is swallowed: the report or another run made it
     * first, and the UPDATE below stamps it. Any other failure of the insert is thrown, for the
     * reason claim() gives: `insertOrIgnore` is MySQL's INSERT IGNORE, which turns a foreign-key
     * or NOT NULL failure into a warning, so the row would silently not exist, the UPDATE would
     * match nothing, and the week would be minted again every hour as if never converted.
     */
    public static function markPrizesConverted(int $masjidId, int $groupId, string $weekStart): void
    {
        $now = now();

        try {
            DB::table('behavior_weeks')->insert([
                'masjid_id' => $masjidId,
                'group_id' => $groupId,
                'week_start' => $weekStart,
                'report_sent_at' => null,
                'recipients_count' => null,
                'prizes_converted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The (group, week) row exists already (the Friday report's claim, or an earlier run).
        }

        DB::table('behavior_weeks')
            ->where('group_id', $groupId)
            ->where('week_start', $weekStart)
            ->whereNull('prizes_converted_at')
            ->update(['prizes_converted_at' => $now, 'updated_at' => $now]);
    }
}
