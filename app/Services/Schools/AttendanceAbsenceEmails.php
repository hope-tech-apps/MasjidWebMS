<?php

namespace App\Services\Schools;

use App\Jobs\SendAttendanceAbsenceMail;
use App\Models\{AttendanceAbsenceMail, AttendanceAbsenceMailChild, Contact, GroupMembership, Masjid};
use App\Support\{AttendanceAbsenceSettings, SchoolCalendarReaders};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

/** Snapshots absences at the school-local sweep time and commits durable claims before queue handoff. */
final class AttendanceAbsenceEmails
{
    public function queue(Masjid $school): int
    {
        $calendar = SchoolCalendarReaders::for((int) $school->id); $date = $calendar->today();
        $marks = app(AttendanceReader::class)->marks((int) $school->id, date: $date)->where('status', 'absent');
        if ($marks->isEmpty()) return 0;
        $edges = GroupMembership::query()->where('masjid_id', $school->id)->where('role', GroupMembership::ROLE_GUARDIAN)->confirmed()->current()
            ->whereIn('group_id', $marks->pluck('class.id'))->whereIn('guardian_of_contact_id', $marks->pluck('student_id'))->with('contact')->get();
        $audiences = [];
        foreach ($edges as $edge) {
            $guardian = $edge->contact; $email = trim((string) $guardian?->email);
            if (! $guardian || ! filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            foreach ($marks as $mark) {
                if ((int) $edge->group_id !== $mark['class']['id'] || (int) $edge->guardian_of_contact_id !== $mark['student_id']) continue;
                $audiences[$guardian->id]['guardian'] = $guardian;
                $audiences[$guardian->id]['children'][$mark['student_id']]['name'] = $mark['first_name'];
                $audiences[$guardian->id]['children'][$mark['student_id']]['classes'][$mark['class']['id']] = $mark['class'];
            }
        }
        $queued = 0;
        foreach ($audiences as $audience) {
            $guardian = $audience['guardian'];
            $mail = DB::transaction(function () use ($guardian, $audience, $school, $date) {
                // The guardian mutex also prevents a second batch with newly marked siblings later that day.
                $current = Contact::query()->where('masjid_id', $school->id)->lockForUpdate()->find($guardian->id);
                if (! $current || ! filter_var(trim((string) $current->email), FILTER_VALIDATE_EMAIL)) return null;
                if (AttendanceAbsenceMail::where('masjid_id', $school->id)->where('guardian_contact_id', $current->id)->whereDate('attendance_date', $date)->exists()) return null;
                $mail = AttendanceAbsenceMail::create(['masjid_id' => $school->id, 'guardian_contact_id' => $current->id, 'guardian_email' => trim($current->email), 'attendance_date' => $date, 'status' => 'queued', 'queued_at' => now()]);
                foreach ($audience['children'] as $student => $child) {
                    AttendanceAbsenceMailChild::create(['masjid_id' => $school->id, 'mail_id' => $mail->id, 'guardian_contact_id' => $current->id, 'student_contact_id' => $student, 'attendance_date' => $date, 'child_name' => $child['name'], 'classes' => array_values($child['classes'])]);
                }
                return $mail;
            });
            if (! $mail) continue;
            try {
                $expires = CarbonImmutable::parse($date, $calendar->timezone())->addDay()->timestamp;
                // A synchronous configuration cannot pace mail; use the installed database queue in that case.
                SendAttendanceAbsenceMail::dispatch((int) $mail->id, (int) $school->id, $expires)
                    ->onConnection(config('queue.default') === 'sync' ? 'database' : config('queue.default'))->afterCommit();
                $queued++;
            } catch (Throwable $e) {
                $mail->forceFill(['status' => 'failed', 'failed_at' => now(), 'exception_class' => $e::class])->save();
                Log::warning('Absence email queue handoff failed.', ['mail_id' => $mail->id, 'exception_class' => $e::class]);
            }
        }
        return $queued;
    }

    /** The office reads explicit public log fields, never a serialized guardian/contact model. */
    public function office(Masjid $school, string $date): array
    {
        $mails = AttendanceAbsenceMail::query()->where('masjid_id', $school->id)->whereDate('attendance_date', $date)->with('children')->orderBy('id')->get();
        return ['send_time' => AttendanceAbsenceSettings::time((int) $school->id), 'rows' => $mails->flatMap(fn ($m) => $m->children->map(fn ($child) => [
            'id' => (int) $child->id, 'guardian_email' => $m->guardian_email, 'child' => $child->child_name,
            'classes' => $child->classes, 'sent_at' => $m->sent_at?->toIso8601String(), 'status' => $m->status,
        ]))->values()->all()];
    }
}
