<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\AttendanceAbsenceMail;
use App\Models\{Contact, GroupMembership, Masjid, SchoolAdmissionMail, SchoolReenrolment, SchoolReenrolmentAnswer, SchoolYear, SchoolApplicationHistory, SchoolReenrolmentDeposit};
use App\Services\Schools\SchoolReenrolments;
use App\Support\{ContactIdentity, SchoolAdmissionsMode, SchoolMailTransport, TenantContext};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\{DB, Log, Mail};
use Throwable;

/** IDs only; a committed claim permits at most one network attempt, with the absence-mail pace. */
class SendSchoolAdmissionMail implements ShouldQueue
{
    use Queueable;
    public int $tries = 0;
    public int $maxExceptions = 1;
    public int $timeout = 60;
    public bool $failOnTimeout = true;
    public function __construct(public int $mailId, public int $schoolId, public int $expiresAt) {}
    public function middleware(): array { return [new RateLimited('school-admissions-mail')]; }
    public function retryUntil(): CarbonImmutable { return CarbonImmutable::createFromTimestampUTC($this->expiresAt); }
    public function handle(): void
    {
        app(TenantContext::class)->runWithout(function () {
            $row = SchoolAdmissionMail::where('masjid_id', $this->schoolId)->find($this->mailId);
            if (! $row || $row->status !== 'queued') return;
            try {
                $school = Masjid::find($this->schoolId);
                if (! SchoolAdmissionsMode::enabled($school, $row->kind === 'reminder' ? 'school_reenrolment' : 'school_applications')) { $this->skip(); return; }
                if ($row->kind === 'application') {
                    $responseId = SchoolApplicationHistory::where('masjid_id', $this->schoolId)->whereKey($row->application_history_id)->value('form_response_id');
                    if (! $responseId || SchoolReenrolmentDeposit::where('masjid_id', $this->schoolId)->where('form_response_id', $responseId)->exists()) { $this->skip(); return; }
                }
                if ($row->kind === 'reminder') {
                    $campaign = SchoolReenrolment::where('masjid_id', $this->schoolId)->find($row->reenrolment_id);
                    $guardian = Contact::where('masjid_id', $this->schoolId)->find($row->guardian_contact_id);
                    if (! $campaign || ! app(SchoolReenrolments::class)->open($campaign) || ! $guardian || ! ContactIdentity::sameAddress($guardian->email, $row->guardian_email)) { $this->skip(); return; }
                    $children = array_values(array_filter($row->children ?? [], function ($child) use ($guardian, $campaign) {
                        if (SchoolReenrolmentAnswer::where('masjid_id', $this->schoolId)->where('reenrolment_id', $campaign->id)->where('student_contact_id', $child['student_contact_id'])->whereNotNull('answer')->exists()) return false;
                        $groups = GroupMembership::where('masjid_id', $this->schoolId)->where('contact_id', $child['student_contact_id'])->participants()->current()->whereHas('group', fn ($q) => $q->where('kind', 'class'))->pluck('group_id');
                        return GroupMembership::where('masjid_id', $this->schoolId)->where('contact_id', $guardian->id)->where('role', 'guardian')->where('guardian_of_contact_id', $child['student_contact_id'])->whereIn('group_id', $groups)->confirmed()->current()->exists();
                    }));
                    if (! $children) { $this->skip(); return; }
                    foreach ($children as &$child) $child['group_id'] = GroupMembership::where('masjid_id', $this->schoolId)->where('contact_id', $guardian->id)->where('role', 'guardian')->where('guardian_of_contact_id', $child['student_contact_id'])->confirmed()->current()
                        ->whereIn('group_id', GroupMembership::where('masjid_id', $this->schoolId)->where('contact_id', $child['student_contact_id'])->participants()->current()->whereHas('group', fn ($q) => $q->where('kind', 'class'))->select('group_id'))->value('group_id');
                    unset($child);
                    $row->children = $children;
                    $row->body = SchoolReenrolments::reminderText($children, SchoolYear::where('masjid_id', $this->schoolId)->findOrFail($campaign->school_year_id)->label, $this->schoolId);
                }
                if (! in_array(config('app.env'), ['local', 'testing'], true) && SchoolMailTransport::unsafe(Mail::mailer()->getSymfonyTransport())) throw new \RuntimeException('School mail transport unavailable');
                if (! DB::table('school_admission_mails')->where('masjid_id', $this->schoolId)->where('id', $this->mailId)->where('status', 'queued')->update(['status' => 'sending', 'attempted_at' => now(), 'body' => $row->body, 'children' => $row->children === null ? null : json_encode($row->children), 'updated_at' => now()])) return;
                Mail::to($row->guardian_email)->send(new AttendanceAbsenceMail($school->name, $school->email, $row->subject, $row->body));
                DB::table('school_admission_mails')->where('masjid_id', $this->schoolId)->where('id', $this->mailId)->where('status', 'sending')->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            } catch (Throwable $e) {
                $this->failed($e);
                Log::warning('School admission email failed.', ['mail_id' => $this->mailId, 'exception_class' => $e::class]);
            }
        });
    }
    private function skip(): void
    {
        DB::table('school_admission_mails')->where('masjid_id', $this->schoolId)->where('id', $this->mailId)->where('status', 'queued')->update(['status' => 'skipped', 'updated_at' => now()]);
    }
    public function failed(?Throwable $exception): void
    {
        DB::table('school_admission_mails')->where('masjid_id', $this->schoolId)->where('id', $this->mailId)->whereIn('status', ['queued', 'sending'])->update(['status' => 'failed', 'failed_at' => now(), 'exception_class' => $exception ? $exception::class : \RuntimeException::class, 'updated_at' => now()]);
    }
}
