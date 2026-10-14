<?php

namespace App\Console\Commands;

use App\Models\{AttendanceAbsenceMail, Masjid, MasjidPointsSetting};
use App\Services\Schools\AttendanceAbsenceEmails;
use App\Support\{AttendanceAbsenceSettings, SchoolCalendarReaders, TenantContext};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Date, DB, Log};
use Throwable;

/** School-local same-day sweep; unique claims remain the authority when schedule locks expire. */
class SendAttendanceAbsenceEmails extends Command
{
    protected $signature = 'attendance:absence-emails';
    protected $description = 'Queue once-daily absent-only notices for confirmed guardians at each school\'s chosen time';

    public function handle(): int
    {
        return app(TenantContext::class)->runWithout(function () {
            $queued = 0; $failures = 0;
            foreach (Masjid::where('org_type', 'school')->get() as $school) {
                if (! AttendanceAbsenceSettings::enabled($school)) continue;
                try {
                    // A killed worker cannot tell us whether delivery happened. Never reclaim its attempt.
                    AttendanceAbsenceMail::where('masjid_id', $school->id)->where('status', 'sending')->where('attempted_at', '<', now()->subMinutes(10))
                        ->update(['status' => 'failed', 'failed_at' => now(), 'exception_class' => \Illuminate\Queue\TimeoutExceededException::class]);
                    $calendar = SchoolCalendarReaders::for((int) $school->id); $now = Date::now()->setTimezone($calendar->timezone());
                    if ($now->format('H:i') < AttendanceAbsenceSettings::time((int) $school->id)) continue;
                    AttendanceAbsenceMail::where('masjid_id', $school->id)->where('status', 'queued')->whereDate('attendance_date', '<', $calendar->today())
                        ->update(['status' => 'failed', 'failed_at' => now(), 'exception_class' => \Illuminate\Queue\MaxAttemptsExceededException::class]);
                    $day = $calendar->schoolDay($calendar->today());
                    if (! $day['in_year'] || ! $day['meeting_day'] || $day['closed']) continue;
                    $claimed = DB::transaction(function () use ($school, $calendar, $now) {
                        $freshSchool = Masjid::whereKey($school->id)->lockForUpdate()->first();
                        if (! AttendanceAbsenceSettings::enabled($freshSchool)) return false;
                        $settings = MasjidPointsSetting::firstOrCreate(['masjid_id' => $school->id]);
                        $date = $calendar->today();
                        if ($settings->attendance_absence_last_date?->toDateString() >= $date) return false;
                        if ($now->format('H:i') < AttendanceAbsenceSettings::time((int) $school->id)) return false;
                        // Commit the school/day snapshot claim even when no register has been taken or nobody is absent.
                        $settings->forceFill(['attendance_absence_last_date' => $date])->save();
                        return true;
                    });
                    if ($claimed) $queued += app(AttendanceAbsenceEmails::class)->queue($school);
                } catch (Throwable $e) {
                    $failures++;
                    Log::warning('Absence email sweep failed.', ['school_id' => $school->id, 'exception_class' => $e::class]);
                }
            }
            $this->line("attendance:absence-emails: {$queued} email(s) queued; {$failures} school(s) failed.");
            return $failures ? self::FAILURE : self::SUCCESS;
        });
    }
}
