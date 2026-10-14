<?php

namespace App\Jobs;

use App\Mail\AttendanceAbsenceMail as AbsenceMail;
use App\Models\{AttendanceAbsenceMail, Contact, GroupMembership, Masjid};
use App\Support\{AttendanceAbsenceSettings, SchoolCalendarReaders, SchoolMailTransport, TenantContext};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\{DB, Log, Mail};
use Throwable;

/** IDs only in the queue. A committed queued→sending claim permits at most one external attempt. */
class SendAttendanceAbsenceMail implements ShouldQueue
{
    use Queueable;

    // Rate-limit releases may repeat until midnight; a claimed delivery itself is never retried.
    public int $tries = 0;
    public int $maxExceptions = 1;
    public int $timeout = 60;
    public bool $failOnTimeout = true;

    public function __construct(public int $mailId, public int $schoolId, public int $expiresAt) {}
    public function middleware(): array { return [new RateLimited('attendance-absence-mail')]; }
    public function retryUntil(): CarbonImmutable { return CarbonImmutable::createFromTimestampUTC($this->expiresAt); }

    public function handle(): void
    {
        app(TenantContext::class)->runWithout(function () {
            $row = AttendanceAbsenceMail::where('masjid_id', $this->schoolId)->with('children')->find($this->mailId);
            if (! $row || $row->status !== 'queued') return;
            try {
                $school = Masjid::find($this->schoolId);
                if (! AttendanceAbsenceSettings::enabled($school)) { $this->skip(); return; }
                $calendar = SchoolCalendarReaders::for($this->schoolId); $date = $row->attendance_date->toDateString();
                $day = $calendar->schoolDay($date);
                if ($calendar->today() !== $date || ! $day['in_year'] || ! $day['meeting_day'] || $day['closed']) { $this->skip(); return; }
                $guardian = Contact::where('masjid_id', $this->schoolId)->find($row->guardian_contact_id);
                if (! $guardian || trim((string) $guardian->email) !== $row->guardian_email || $row->children->isEmpty()) { $this->skip(); return; }
                foreach ($row->children as $child) {
                    if ((int) $child->masjid_id !== $this->schoolId || (int) $child->guardian_contact_id !== (int) $row->guardian_contact_id || $child->attendance_date->toDateString() !== $date || ! $child->classes) { $this->skip(); return; }
                    foreach ($child->classes as $class) {
                        $confirmed = GroupMembership::where('masjid_id', $this->schoolId)->where('group_id', $class['id'])->where('contact_id', $guardian->id)
                            ->where('role', GroupMembership::ROLE_GUARDIAN)->where('guardian_of_contact_id', $child->student_contact_id)->confirmed()->current()->exists();
                        $enrolled = GroupMembership::where('masjid_id', $this->schoolId)->where('group_id', $class['id'])->where('contact_id', $child->student_contact_id)->participants()->current()->exists();
                        if (! $confirmed || ! $enrolled) { $this->skip(); return; }
                    }
                }
                if (! in_array(config('app.env'), ['local', 'testing'], true) && SchoolMailTransport::unsafe(Mail::mailer()->getSymfonyTransport())) {
                    throw new \RuntimeException('School mail transport unavailable');
                }
                // This update commits before the network call. A second worker, timeout or crash can never send again.
                if (! DB::table('attendance_absence_mails')->where('masjid_id', $this->schoolId)->where('id', $this->mailId)->where('status', 'queued')->update(['status' => 'sending', 'attempted_at' => now(), 'updated_at' => now()])) return;
                $dateLabel = CarbonImmutable::parse($date, 'UTC')->format('F j, Y');
                $lines = $row->children->map(fn ($child) => ($child->child_name ?: 'Student').' was marked absent from '.implode(', ', array_column($child->classes, 'name')).' today, '.$dateLabel.'.');
                $contacts = [];
                if (trim((string) $school->phone) !== '') $contacts[] = 'call '.$school->phone;
                if (trim((string) $school->email) !== '') $contacts[] = 'email '.$school->email;
                $office = $contacts ? 'To reach the office, '.implode(' or ', $contacts).'.' : 'Please contact the school office.';
                $body = $lines->implode("\n")."\n\n".$office;
                Mail::to($row->guardian_email)->send(new AbsenceMail($school->name, $school->email, $school->name.': Attendance for '.$dateLabel, $body));
                DB::table('attendance_absence_mails')->where('masjid_id', $this->schoolId)->where('id', $this->mailId)->where('status', 'sending')->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            } catch (Throwable $e) {
                $this->failed($e);
                Log::warning('Absence email delivery failed.', ['mail_id' => $this->mailId, 'exception_class' => $e::class]);
            }
        });
    }

    private function skip(): void
    {
        DB::table('attendance_absence_mails')->where('masjid_id', $this->schoolId)->where('id', $this->mailId)->where('status', 'queued')->update(['status' => 'skipped', 'updated_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('attendance_absence_mails')->where('masjid_id', $this->schoolId)->where('id', $this->mailId)->whereIn('status', ['queued', 'sending'])
            ->update(['status' => 'failed', 'failed_at' => now(), 'exception_class' => $exception ? $exception::class : \RuntimeException::class, 'updated_at' => now()]);
    }
}
