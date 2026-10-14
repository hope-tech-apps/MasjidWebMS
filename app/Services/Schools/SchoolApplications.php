<?php

declare(strict_types=1);

namespace App\Services\Schools;

use App\Http\Controllers\AdminDashboard\GroupMembershipsController;
use App\Http\Requests\Admin\Groups\StoreGroupMembershipRequest;
use App\Jobs\SendSchoolAdmissionMail;
use App\Models\{Contact, Form, FormResponse, Group, GroupMembership, Masjid, SchoolAdmissionMail, SchoolAdmissionSetting, SchoolApplication, SchoolApplicationHistory, User};
use App\Support\{ContactIdentity, GradeLevel, SchoolAdmissionsMode};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Application decisions sit beside the response, preserving Forms' existing operational statuses. */
final class SchoolApplications
{
    public const STATUSES = ['new', 'accepted', 'wait_listed', 'declined'];
    public const TEXTS = [
        'accepted' => 'A place at {school} has been offered for {children}. Please contact the school office about the next steps.',
        'wait_listed' => 'The application for {children} is on the waiting list at {school}. We will contact you if a place becomes available.',
        'declined' => 'We are unable to offer a place at {school} for {children}. Please contact the school office if you would like to discuss this decision.',
    ];

    public function setting(int $school): array
    {
        $row = SchoolAdmissionSetting::where('masjid_id', $school)->first();
        return ['application_form_ids' => $row?->application_form_ids ?? [], ...collect(self::TEXTS)->mapWithKeys(fn ($default, $key) => [$key.'_text' => $row?->{$key.'_text'} ?? $default])->all()];
    }

    public function form(int $id, int $school): Form
    {
        $form = Form::where('masjid_id', $school)->findOrFail($id);
        abort_unless(in_array((int) $form->id, array_map('intval', $this->setting($school)['application_form_ids']), true), 404);
        return $form;
    }

    public function response(int $id, int $school): FormResponse
    {
        $response = FormResponse::where('masjid_id', $school)->findOrFail($id);
        $response->setRelation('form', $this->form((int) $response->form_id, $school));
        return $response;
    }

    public function detail(FormResponse $response): array
    {
        $history = SchoolApplicationHistory::where('form_response_id', $response->id)->orderByDesc('id')->get();
        $users = User::whereIn('id', $history->pluck('changed_by_user_id'))->get(['id', 'name'])->keyBy('id');
        $mails = SchoolAdmissionMail::whereIn('application_history_id', $history->pluck('id'))->get()->groupBy('application_history_id');
        return ['response_id' => $response->id, 'status' => SchoolApplication::where('form_response_id', $response->id)->value('status') ?? 'new',
            'children' => SchoolAdmissionIdentity::children($response->form, $response), 'guardians' => SchoolAdmissionIdentity::guardians($response->form, $response),
            'history' => $history->map(fn ($h) => ['id' => $h->id, 'status' => $h->status, 'note' => $h->note, 'emailed' => $h->emailed,
                'changed_by' => $users->get($h->changed_by_user_id)?->name, 'changed_at' => $h->created_at->toIso8601String(),
                'mails' => ($mails[$h->id] ?? collect())->map(fn ($m) => ['guardian_email' => $m->guardian_email, 'status' => $m->status, 'sent_at' => $m->sent_at?->toIso8601String(), 'failed_at' => $m->failed_at?->toIso8601String()])->all()])->all()];
    }

    public function change(FormResponse $response, string $status, ?string $note, bool $withoutEmail, int $actor): array
    {
        return DB::transaction(function () use ($response, $status, $note, $withoutEmail, $actor) {
            $school = Masjid::whereKey($response->masjid_id)->lockForUpdate()->firstOrFail();
            abort_unless(SchoolAdmissionsMode::enabled($school, 'school_applications'), 403);
            $response = $this->response((int) $response->id, (int) $school->id);
            $old = SchoolApplication::firstOrNew(['form_response_id' => $response->id]);
            if (($old->status ?? 'new') === $status) return $this->detail($response);
            $recipients = SchoolAdmissionIdentity::guardians($response->form, $response);
            if (! $withoutEmail && $status !== 'new' && ! $recipients) throw ValidationException::withMessages(['status' => 'This response has no guardian email. Use mark without emailing after checking it.']);
            $old->fill(['status' => $status])->save();
            $email = ! $withoutEmail && $status !== 'new';
            $history = SchoolApplicationHistory::create(['form_response_id' => $response->id, 'status' => $status, 'note' => $note, 'changed_by_user_id' => $actor, 'emailed' => $email]);
            if ($email) {
                $children = SchoolAdmissionIdentity::children($response->form, $response);
                $names = array_map(fn ($c) => trim($c['first_name'].' '.$c['last_name']), $children);
                $body = strtr($this->setting((int) $school->id)[$status.'_text'], ['{school}' => $school->name, '{children}' => implode(', ', $names)]);
                foreach ($recipients as $guardian) {
                    $row = SchoolAdmissionMail::create(['kind' => 'application', 'application_history_id' => $history->id, 'requested_by_user_id' => $actor, 'guardian_email' => $guardian['email'],
                        'subject' => $school->name.': Application update', 'body' => $body, 'status' => 'queued', 'queued_at' => now()]);
                    self::dispatch($row);
                }
            }
            return $this->detail($response);
        });
    }

    public static function dispatch(SchoolAdmissionMail $row): void
    {
        SendSchoolAdmissionMail::dispatch((int) $row->id, (int) $row->masjid_id, now()->addDay()->timestamp)
            ->onConnection('database')->afterCommit();
    }

    public function listing(int $school): array
    {
        $forms = Form::where('masjid_id', $school)->whereIn('id', $this->setting($school)['application_form_ids'])->get()->keyBy('id');
        $responses = FormResponse::where('masjid_id', $school)->whereIn('form_id', $forms->keys())->orderBy('submitted_at')->orderBy('id')->get();
        $states = SchoolApplication::whereIn('form_response_id', $responses->pluck('id'))->get()->keyBy('form_response_id');
        $positions = []; $children = [];
        foreach ($responses as $response) {
            $form = $forms[$response->form_id]; $status = $states->get($response->id)?->status ?? 'new';
            foreach (SchoolAdmissionIdentity::children($form, $response) as $child) {
                $grade = GradeLevel::key($child['grade']) ?? '';
                $position = null;
                if ($status === 'wait_listed') { $positions[$grade] = ($positions[$grade] ?? 0) + 1; $position = $positions[$grade]; }
                $children[] = $child + ['response_id' => $response->id, 'form_id' => $form->id, 'form_name' => $form->name, 'status' => $status, 'submitted_at' => $response->submitted_at?->toIso8601String(), 'waitlist_position' => $position];
            }
        }
        return ['children' => $children, 'classes' => Group::where('kind', 'class')->where('is_active', true)->get(['id', 'name'])->toArray(), 'settings' => $this->setting($school)];
    }

    /** Reuse the office's existing roster add and confirmedByStaff path with its usual contact lock. */
    private function add(Group $class, Contact $contact, ?Contact $ward, User $actor): GroupMembership
    {
        $payload = ['contact_id' => $contact->id, 'role' => $ward ? 'guardian' : 'member'];
        if ($ward) $payload['guardian_of_contact_id'] = $ward->id;
        $request = StoreGroupMembershipRequest::create('/', 'POST', $payload);
        $request->setUserResolver(fn () => $actor);
        $result = app(GroupMembershipsController::class)->store($request, $class->masjid_id, $class->id);
        if ($result->getStatusCode() >= 400) throw ValidationException::withMessages(['child' => $result->getData(true)['message']]);
        return GroupMembership::findOrFail($result->getData(true)['data']['id']);
    }

    public function roster(FormResponse $response, int $entry, Group $class, User $actor): array
    {
        return DB::transaction(function () use ($response, $entry, $class, $actor) {
            $school = Masjid::whereKey($response->masjid_id)->lockForUpdate()->firstOrFail();
            abort_unless(SchoolAdmissionsMode::enabled($school, 'school_applications'), 403);
            $response = $this->response((int) $response->id, (int) $school->id);
            if (SchoolApplication::where('form_response_id', $response->id)->value('status') !== 'accepted') throw ValidationException::withMessages(['status' => 'Accept this application before adding a child to a roster.']);
            if ($class->kind !== 'class' || ! $class->is_active || (int) $class->masjid_id !== (int) $school->id) abort(404);
            $child = collect(SchoolAdmissionIdentity::children($response->form, $response))->firstWhere('entry_index', $entry);
            if (! $child) abort(404);
            $guardians = SchoolAdmissionIdentity::guardians($response->form, $response);
            if (! $guardians) throw ValidationException::withMessages(['guardian' => 'Record a guardian email on the form before adding this child.']);
            $matches = SchoolAdmissionIdentity::childMatches($response->form, $response, $child);
            if ($matches->count() > 1) throw ValidationException::withMessages(['child' => 'More than one child matches this household and name. Resolve the identities in the directory first.']);
            $student = $matches->first();
            if ($student) {
                Contact::whereKey($student->id)->lockForUpdate()->firstOrFail();
                if (GroupMembership::where('contact_id', $student->id)->participants()->current()->exists()) throw ValidationException::withMessages(['child' => 'This child is already on a roster. Use the existing class move instead.']);
            }
            // An unlinked same-name child anywhere in school needs identity review before a new contact is created.
            $sameClass = GroupMembership::whereHas('group', fn ($q) => $q->where('kind', 'class'))->participants()->current()->with('contact')->get()->contains(fn ($m) => $m->contact && SchoolAdmissionIdentity::name($m->contact->first_name) === SchoolAdmissionIdentity::name($child['first_name']) && SchoolAdmissionIdentity::name($m->contact->last_name) === SchoolAdmissionIdentity::name($child['last_name']));
            if ($sameClass) throw ValidationException::withMessages(['child' => 'A child with this name is already on a school roster. Check their identity in the directory first.']);
            $student ??= Contact::create(['first_name' => $child['first_name'], 'last_name' => $child['last_name']]);
            $membership = $this->add($class, $student, null, $actor);
            $membership->update(['grade_label' => $child['grade'] ?: null]);
            foreach ($guardians as $g) {
                $adult = ContactIdentity::keepExactMatches(Contact::whereRaw('LOWER(TRIM(email)) = ?', [$g['email']])->get(), 'email', $g['email'])->first();
                if (! $adult) {
                    $parts = preg_split('/\s+/u', $g['name'], 2);
                    $adult = Contact::create(['first_name' => $parts[0], 'last_name' => $parts[1] ?? null, 'email' => $g['email']]);
                }
                $this->add($class, $adult, $student, $actor);
            }
            return ['membership_id' => $membership->id, 'student_contact_id' => $student->id, 'message' => 'Added to the class roster with guardians confirmed by the office.'];
        });
    }
}
