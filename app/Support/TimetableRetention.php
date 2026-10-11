<?php

namespace App\Support;

use App\Models\{Masjid, User};
use Illuminate\Support\Facades\DB;

/** Durable, hidden hints make an unused OFF path query-free, including before migration. */
final class TimetableRetention
{
    private array $schools = [];
    private array $accounts = [];

    public static function instance(): self
    {
        if (! app()->bound(self::class)) app()->scoped(self::class, fn () => new self());
        return app(self::class);
    }

    /** Called in the row's transaction; request-local positives also cover previously loaded models. */
    public function mark(int $school, ?int $account = null): void
    {
        // Do not skip a durable write on a request-local positive: a nested transaction
        // may have rolled the previous hint back while this instance is still alive.
        DB::table('masjids')->where('id', $school)->where('has_timetable_records', false)->update(['has_timetable_records'=>true]);
        $this->schools[$school] = true;
        ClassSubjectMode::forget($school);
        if ($account !== null) {
            DB::table('users')->where('id', $account)->where('has_timetable_records', false)->update(['has_timetable_records'=>true]);
            $this->accounts[$account] = true;
        }
    }

    /** Bulk link replacements use query builders, so their writer refreshes account hints explicitly. */
    public function refreshAccounts(array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            $held = DB::table('timetable_meeting_teachers')->where('user_id', $id)->exists();
            DB::table('users')->where('id', $id)->update(['has_timetable_records'=>$held]);
            if ($held) $this->accounts[$id] = true; else unset($this->accounts[$id]);
        }
    }

    public function refreshSchool(int $id): void
    {
        $held = false;
        foreach (['timetable_period_sets','timetable_periods','timetable_days','timetable_class_days','timetable_rooms','timetable_class_rooms','timetable_meetings','timetable_meeting_teachers'] as $table) {
            if (DB::table($table)->where('masjid_id', $id)->exists()) { $held = true; break; }
        }
        DB::table('masjids')->where('id', $id)->update(['has_timetable_records'=>$held]);
        if ($held) $this->schools[$id] = true; else unset($this->schools[$id]);
        ClassSubjectMode::forget($id);
    }

    public function school(int $id): bool
    {
        return isset($this->schools[$id]) || ClassSubjectMode::timetableRecordsHeld($id);
    }

    public function account(User $account): bool
    {
        return isset($this->accounts[$account->id]) || (bool) $account->getAttribute('has_timetable_records');
    }
}
