<?php

declare(strict_types=1);

namespace App\Services\Schools;

use App\Models\{Contact, Form, FormResponse, GroupMembership, Masjid, SchoolAdmissionMail, SchoolReenrolment, SchoolReenrolmentAnswer, SchoolReenrolmentDeposit, SchoolYear};
use App\Support\{ContactIdentity, GradeLevel, SchoolAdmissionsMode, SchoolCalendarReaders};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Dated answers are office records; they never change rosters or create money. */
final class SchoolReenrolments
{
    public function open(SchoolReenrolment $campaign): bool
    {
        $today = SchoolCalendarReaders::for((int) $campaign->masjid_id)->today();
        return $today >= $campaign->opens_on->toDateString() && $today <= $campaign->closes_on->toDateString();
    }

    public function campaignPayload(SchoolReenrolment $campaign): array
    {
        return ['id' => $campaign->id, 'school_year_id' => $campaign->school_year_id, 'year' => SchoolYear::find($campaign->school_year_id)?->label,
            'opens_on' => $campaign->opens_on->toDateString(), 'closes_on' => $campaign->closes_on->toDateString(), 'open' => $this->open($campaign), 'deposit_form_id' => $campaign->deposit_form_id];
    }

    public function roster(): \Illuminate\Support\Collection
    {
        return GroupMembership::where('role', 'member')->current()->whereHas('group', fn ($q) => $q->where('kind', 'class'))->with(['contact:id,first_name,last_name', 'group:id,name'])->orderBy('id')->get();
    }

    /** Only propose a next grade actually served by a current school class; blank needs office choice. */
    private function proposedGrade(GroupMembership $child): string
    {
        $next = GradeLevel::next($child->grade_label);
        if ($next === null) return '';
        $served = GroupMembership::where('masjid_id', $child->masjid_id)->participants()->current()
            ->whereHas('group', fn ($q) => $q->where('kind', 'class')->where('is_active', true))->pluck('grade_label')->all();
        return GradeLevel::in($next, $served) ? $next : '';
    }

    /** Opening/updating dates snapshots proposals, without answering for families or moving any roster. */
    public function proposals(SchoolReenrolment $campaign): void
    {
        foreach ($this->roster()->unique('contact_id') as $child) {
            SchoolReenrolmentAnswer::firstOrCreate(['masjid_id' => $campaign->masjid_id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id],
                ['next_grade' => $this->proposedGrade($child)]);
        }
    }

    public function child(SchoolReenrolment $campaign, GroupMembership $membership, ?int $group = null): array
    {
        $answer = SchoolReenrolmentAnswer::where('reenrolment_id', $campaign->id)->where('student_contact_id', $membership->contact_id)->first();
        $by = $answer?->recorded_by_office ? null : ($answer?->answered_by_contact_id ? Contact::find($answer->answered_by_contact_id) : null);
        $out = ['student_contact_id' => $membership->contact_id, 'membership_id' => $membership->id, 'name' => trim($membership->contact?->first_name.' '.$membership->contact?->last_name),
            'grade' => $membership->grade_label, 'next_grade' => $answer?->next_grade ?? $this->proposedGrade($membership),
            'answer' => $answer?->answer, 'recorded_by_office' => (bool) $answer?->recorded_by_office,
            'answered_by' => $answer?->recorded_by_office ? 'Office' : ($by ? trim($by->first_name.' '.$by->last_name) : null),
            'answered_at' => SchoolAdmissionsMode::timestamp((int) $campaign->masjid_id, $answer?->answered_at), 'open' => $this->open($campaign), 'year' => SchoolYear::find($campaign->school_year_id)?->label];
        $linked = SchoolReenrolmentDeposit::where('masjid_id', $campaign->masjid_id)->where('reenrolment_id', $campaign->id)->where('student_contact_id', $membership->contact_id)->first();
        $response = $linked ? FormResponse::where('masjid_id', $campaign->masjid_id)->find($linked->form_response_id) : null;
        $formId = $response?->form_id ?? $campaign->deposit_form_id;
        $form = $formId ? Form::where('masjid_id', $campaign->masjid_id)->find($formId) : null;
        if ($form && ($response || $form->takesPayment())) {
            $responses = SchoolReenrolmentDeposit::where('masjid_id', $campaign->masjid_id)->where('reenrolment_id', $campaign->id)->where('student_contact_id', $membership->contact_id)->pluck('form_response_id');
            $payments = FormResponse::where('masjid_id', $campaign->masjid_id)->whereIn('id', $responses)->orderByDesc('id')->get();
            $paid = $payments->contains(function ($r) use ($form) {
                $r->setRelation('form', $form);
                return ! $r->isCancelled() && $r->charge_flag === null && $r->isPaid();
            });
            $pending = $payments->first(fn ($r) => ! $r->isCancelled() && $r->charge_flag === null && ! $r->isPaid());
            $out['deposit'] = ['paid' => $paid, 'form_name' => $form->name, 'url' => '/family/'.$campaign->masjid_id.'/deposit/'.$campaign->id.'/'.($group ?? $membership->group_id).'/'.$membership->id.($pending ? '?form_response='.rawurlencode($pending->uuid) : '')];
        }
        return $out;
    }

    public function answer(SchoolReenrolment $campaign, GroupMembership $child, array $values, ?Contact $guardian = null, ?int $office = null): array
    {
        return DB::transaction(function () use ($campaign, $child, $values, $guardian, $office) {
            $school = Masjid::whereKey($campaign->masjid_id)->lockForUpdate()->firstOrFail();
            abort_unless(SchoolAdmissionsMode::enabled($school, 'school_reenrolment'), 403);
            $campaign = SchoolReenrolment::findOrFail($campaign->id);
            $child = GroupMembership::where('role', 'member')->current()->with('group')->findOrFail($child->id);
            abort_unless($child->group?->kind === 'class', 404);
            if ($guardian !== null) {
                $guardian = Contact::findOrFail($guardian->id);
                abort_unless(app(\App\Support\GroupAudience::class)->mayReceiveAwardsAbout($guardian, $child->group, $child), 403);
            }
            if ($office === null && ! $this->open($campaign)) throw ValidationException::withMessages(['answer' => 'Re-enrolment is closed.']);
            $row = SchoolReenrolmentAnswer::firstOrNew(['reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id]);
            if (array_key_exists('answer', $values)) $row->fill(['answer' => $values['answer'], 'answered_by_contact_id' => $guardian?->id, 'answered_by_user_id' => $office,
                'recorded_by_office' => $office !== null, 'answered_at' => now()]);
            if ($row->next_grade === null) $row->next_grade = $this->proposedGrade($child);
            if (array_key_exists('next_grade', $values)) $row->next_grade = $values['next_grade'] ?? '';
            $row->save(); return $this->child($campaign, $child);
        });
    }

    public function officeList(SchoolReenrolment $campaign): array
    {
        $children = $this->roster()->groupBy('contact_id')->map(function ($memberships) use ($campaign) {
            return $this->child($campaign, $memberships->first()) + ['classes' => $memberships->map(fn ($m) => ['id' => $m->group_id, 'name' => $m->group->name])->values()->all()];
        })->values()->all();
        usort($children, fn ($a, $b) => [GradeLevel::position($a['grade']) ?? 99, $a['name'], $a['student_contact_id']] <=> [GradeLevel::position($b['grade']) ?? 99, $b['name'], $b['student_contact_id']]);
        $mails = SchoolAdmissionMail::where('reenrolment_id', $campaign->id)->where('kind', 'reminder')->orderByDesc('id')->get();
        $lastSent = $mails->where('status', 'sent')->sortByDesc('sent_at')->first();
        $batch = $lastSent ? $mails->where('status', 'sent')->filter(fn ($m) => $lastSent->reminder_batch ? $m->reminder_batch === $lastSent->reminder_batch : $m->id === $lastSent->id) : collect();
        return $this->campaignPayload($campaign) + ['children' => $children, 'reminder_count' => count($this->reminderRecipients($campaign)),
            'last_reminder' => $lastSent ? ['at' => SchoolAdmissionsMode::timestamp((int) $campaign->masjid_id, $batch->sortByDesc('sent_at')->first()->sent_at), 'count' => $batch->count()] : null,
            'mails' => $mails->map(function ($mail) use ($campaign) {
                $out = $mail->toArray();
                foreach (['queued_at', 'attempted_at', 'sent_at', 'failed_at', 'created_at', 'updated_at'] as $key) $out[$key] = SchoolAdmissionsMode::timestamp((int) $campaign->masjid_id, $mail->$key);
                return $out;
            })->all()];
    }

    /** The preview count and the send share the same confirmed, unanswered, unqueued audience. */
    private function reminderRecipients(SchoolReenrolment $campaign): array
    {
        $roster = $this->roster(); $answered = SchoolReenrolmentAnswer::where('reenrolment_id', $campaign->id)->whereNotNull('answer')->pluck('student_contact_id');
        $unanswered = $roster->whereNotIn('contact_id', $answered);
        $edges = GroupMembership::where('role', 'guardian')->confirmed()->current()->whereIn('guardian_of_contact_id', $unanswered->pluck('contact_id'))->with('contact')->get();
        $recipients = [];
        foreach ($edges->groupBy('contact_id') as $guardianId => $guardianships) {
            $guardian = $guardianships->first()->contact;
            $email = ContactIdentity::submittedAddress($guardian?->email ?? '');
            if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $children = $unanswered->filter(fn ($m) => $guardianships->contains(fn ($g) => $g->guardian_of_contact_id === $m->contact_id && $g->group_id === $m->group_id))->unique('contact_id')
                ->map(fn ($m) => ['student_contact_id' => $m->contact_id, 'group_id' => $m->group_id, 'name' => trim($m->contact->first_name.' '.$m->contact->last_name)])->values()->all();
            if (! $children || SchoolAdmissionMail::where('reenrolment_id', $campaign->id)->where('guardian_contact_id', $guardianId)->whereIn('status', ['queued', 'sending'])->exists()) continue;
            $recipients[] = ['guardian_id' => $guardianId, 'email' => $email, 'children' => $children];
        }
        return $recipients;
    }

    public function reminders(SchoolReenrolment $campaign, int $actor): int
    {
        return DB::transaction(function () use ($campaign, $actor) {
            $school = Masjid::whereKey($campaign->masjid_id)->lockForUpdate()->firstOrFail();
            abort_unless(SchoolAdmissionsMode::enabled($school, 'school_reenrolment'), 403);
            $campaign = SchoolReenrolment::findOrFail($campaign->id);
            if (! $this->open($campaign)) throw ValidationException::withMessages(['reminders' => 'Re-enrolment is closed.']);
            $count = 0; $queuedAt = now(); $batch = (string) \Illuminate\Support\Str::uuid();
            foreach ($this->reminderRecipients($campaign) as $recipient) {
                ['guardian_id' => $guardianId, 'email' => $email, 'children' => $children] = $recipient;
                $year = SchoolYear::findOrFail($campaign->school_year_id)->label;
                $row = SchoolAdmissionMail::create(['kind' => 'reminder', 'reminder_batch' => $batch, 'reenrolment_id' => $campaign->id, 'guardian_contact_id' => $guardianId, 'guardian_email' => $email,
                    'requested_by_user_id' => $actor, 'children' => $children, 'subject' => $school->name.': Re-enrolment for '.$year,
                    'body' => self::reminderText($children, $year, (int) $school->id), 'status' => 'queued', 'queued_at' => $queuedAt]);
                SchoolApplications::dispatch($row); $count++;
            }
            return $count;
        });
    }

    public static function reminderText(array $children, string $year, int $school): string
    {
        return 'Please tell us whether '.implode(', ', array_column($children, 'name')).' will return for '.$year.'. Open the family portal to answer for each child.'
            ."\n\n".collect($children)->map(fn ($child) => rtrim(config('app.url'), '/').'/family/'.$school.'/classes/'.$child['group_id'].'#reenrolment')->unique()->implode("\n")."\n\nPlease contact the school office if you need help.";
    }
}
