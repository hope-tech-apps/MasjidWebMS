<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\{CopyStudentAllergiesRequest, RecordStudentAllergiesRequest};
use App\Models\{Contact, Form, Group, GroupMembership, StudentAllergy};
use App\Support\{SchoolRecordsCsv, StudentAllergies};

class StudentAllergiesController extends Controller
{
    private function student($groupId, $membershipId): Contact
    {
        $group = Group::findOrFail($groupId);
        $membership = $group->memberships()->findOrFail($membershipId);
        abort_unless($group->teachesStudents() && $membership->role === GroupMembership::ROLE_MEMBER, 422);
        return Contact::findOrFail($membership->contact_id);
    }

    public function show($masjid_id, $group_id, $membership_id)
    {
        $contact = $this->student($group_id, $membership_id);
        $record = StudentAllergy::query()->where('contact_id', $contact->id)->first();
        return response()->json(['status' => 'success', 'data' => $record?->officePayload() ?? ['allergies' => null, 'source' => null, 'form_response_id' => null, 'changed_at' => null]])->header('Cache-Control', 'no-store');
    }

    public function update(RecordStudentAllergiesRequest $request, $masjid_id, $group_id, $membership_id)
    {
        $contact = $this->student($group_id, $membership_id);
        $record = StudentAllergies::edit($contact, $request->validated('allergies'), $request->user()?->id);
        return response()->json(['status' => 'success', 'data' => $record->officePayload()])->header('Cache-Control', 'no-store');
    }

    public function forms()
    {
        return response()->json(['status' => 'success', 'data' => Form::query()->where('masjid_id', app(\App\Support\TenantContext::class)->get())->get(['id', 'name', 'schema'])->filter(function ($form) {
            $section = StudentAllergies::childSection($form);
            $fields = collect($section['fields'] ?? collect($form->sections())->reject(fn ($s) => ! empty($s['repeatable']))->flatMap(fn ($s) => $s['fields'] ?? [])->all())->pluck('name');
            return $fields->contains($section ? 'firstName' : 'studentFirstName') && $fields->contains($section ? 'lastName' : 'studentLastName');
        })->map(fn ($form) => ['id' => $form->id, 'name' => $form->name])->values()])->header('Cache-Control', 'no-store');
    }

    public function copy(CopyStudentAllergiesRequest $request, $masjid_id, StudentAllergies $service)
    {
        // Form is not globally tenant-scoped; resolve through the bound school explicitly.
        $form = Form::query()->where('masjid_id', app(\App\Support\TenantContext::class)->get())->findOrFail($request->integer('form_id'));
        return response()->json(['status' => 'success', 'data' => $service->copy($form, $request->validated('field'), $request->boolean('dry_run'), $request->user()?->id)])->header('Cache-Control', 'no-store');
    }

    public function export($masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);
        abort_unless($group->teachesStudents(), 422);
        $members = $group->memberships()->where('role', 'member')->current()->with('contact:id,first_name,last_name')->orderBy('id')->get();
        $notes = StudentAllergies::forRoster($group, $members);
        return response()->streamDownload(function () use ($members, $notes) {
            $out = SchoolRecordsCsv::open();
            SchoolRecordsCsv::row($out, ['First name', 'Last name', 'Grade', 'Allergies']);
            foreach ($members as $m) {
                SchoolRecordsCsv::row($out, array_map(SchoolRecordsCsv::text(...), [$m->contact?->first_name, $m->contact?->last_name, $m->grade_label, $notes[$m->id]['unavailable'] ?? false ? 'Allergy note unavailable: ask the office' : ($notes[$m->id]['allergies'] ?? null)]));
            }
            fclose($out);
        }, 'class-roster.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
