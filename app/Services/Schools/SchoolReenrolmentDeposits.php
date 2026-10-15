<?php

declare(strict_types=1);

namespace App\Services\Schools;

use App\Models\{Contact, Form, FormResponse, GroupMembership, Masjid, SchoolReenrolment, SchoolReenrolmentAnswer, SchoolReenrolmentDeposit};
use App\Support\{ContactIdentity, FormSchema, GroupAudience, SchoolAdmissionsMode, StudentAllergies};
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/** An encrypted child/year credential links existing form payments; it never records payment itself. */
final class SchoolReenrolmentDeposits
{
    public const DUPLICATE = 'A deposit response already exists for this child and school year. Open it from the family portal.';
    public function page(SchoolReenrolment $campaign, GroupMembership $child, Contact $guardian): array
    {
        $service = app(SchoolReenrolments::class);
        $answer = SchoolReenrolmentAnswer::where('reenrolment_id', $campaign->id)->where('student_contact_id', $child->contact_id)->first();
        $existing = SchoolReenrolmentDeposit::where('masjid_id', $campaign->masjid_id)->where('reenrolment_id', $campaign->id)->where('student_contact_id', $child->contact_id)->first();
        if (! $existing && (! $service->open($campaign) || $answer?->answer !== 'returning')) throw ValidationException::withMessages(['deposit' => 'Confirm that this child is returning while re-enrolment is open first.']);
        $form = Form::where('masjid_id', $campaign->masjid_id)->findOrFail($existing ? FormResponse::where('masjid_id', $campaign->masjid_id)->findOrFail($existing->form_response_id)->form_id : $campaign->deposit_form_id);
        abort_unless($existing || $form->takesPayment(), 404);
        $data = [];
        foreach ($form->sections() as $section) {
            if (! empty($section['repeatable'])) continue;
            foreach ($section['fields'] ?? [] as $field) {
                $key = $field['name'] ?? '';
                if (preg_match('/^(parent|parent1|guardian|guardian1)(Email|Name|FirstName|LastName|Phone)$/', $key, $m)) $data[$key] = match ($m[2]) {
                    'Email' => $guardian->email, 'Name' => trim($guardian->first_name.' '.$guardian->last_name), 'FirstName' => $guardian->first_name, 'LastName' => $guardian->last_name, 'Phone' => $guardian->phone,
                };
            }
        }
        $identity = $form->identityMap();
        foreach (['email' => $guardian->email, 'name' => trim($guardian->first_name.' '.$guardian->last_name), 'phone' => $guardian->phone] as $slot => $value) if (is_string($identity[$slot])) $data[$identity[$slot]] = $value;
        $section = StudentAllergies::childSection($form);
        $entry = ['firstName' => $child->contact->first_name, 'lastName' => $child->contact->last_name, 'grade' => $answer?->next_grade ?? ''];
        if ($section) $data[$section['id']] = [$entry];
        else $data += ['studentFirstName' => $entry['firstName'], 'studentLastName' => $entry['lastName'], 'studentGrade' => $entry['grade'], 'grade' => $entry['grade']];
        if ($section) {
            foreach (['schoolGrade', 'gradeLevel', 'studentGrade', 'currentGrade'] as $key) $data[$section['id']][0][$key] = $entry['grade'];
        }
        $data = FormSchema::for($form)->only($data);
        $token = Crypt::encryptString(json_encode(['campaign' => $campaign->id, 'membership' => $child->id, 'guardian' => $guardian->id, 'school' => $campaign->masjid_id, 'form' => $form->id, 'expires' => now()->addHours(2)->timestamp]));
        return ['form_id' => $form->id, 'name' => $form->name, 'child_name' => trim($child->contact->first_name.' '.$child->contact->last_name), 'response_uuids' => FormResponse::where('masjid_id', $campaign->masjid_id)->where('form_id', $form->id)->whereIn('id', SchoolReenrolmentDeposit::where('masjid_id', $campaign->masjid_id)->where('reenrolment_id', $campaign->id)->where('student_contact_id', $child->contact_id)->pluck('form_response_id'))->pluck('uuid')->all(), 'schema' => \App\Support\FormOptionSources::schema($form), 'data' => $data, 'token' => $token,
            'card' => $form->canTakeCardNow(), 'office' => $form->takesOfficePayment(), 'office_instructions' => $form->officeInstructions(),
            'cover_fees' => $form->allowsFeeCoverage(), 'requires_fee_coverage' => $form->requiresFeeCoverage(), 'quote' => \App\Support\FormPayment::quote($form, $data, false, false)];
    }

    /** Only the signed child's exact family path on the app or this school's configured portal may receive a card return. */
    public static function returnBase(\Illuminate\Http\Request $request, int $school): ?string
    {
        try { $claim = json_decode(Crypt::decryptString((string) $request->input('reenrolment_token')), true, flags: JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
        if (! is_array($claim) || (int) ($claim['school'] ?? 0) !== $school || ($claim['expires'] ?? 0) < now()->timestamp) return null;
        $path = '/family/'.$school.'/deposit/'.($claim['campaign'] ?? '').'/';
        $member = GroupMembership::where('masjid_id', $school)->find($claim['membership'] ?? 0);
        if (! $member) return null;
        $path .= $member->group_id.'/'.$member->id;
        if ($request->input('return_path') !== $path) return null;
        $origin = $request->header('Origin');
        if (! is_string($origin) || ! preg_match(\App\Support\FormPaymentReturn::ORIGIN_PATTERN, $origin)) return null;
        $appOrigin = rtrim((string) config('app.url'), '/');
        $portalSchool = config('portal.hosts', [])[parse_url($origin, PHP_URL_HOST)] ?? null;
        if ($origin !== $appOrigin && ! ($portalSchool !== null && (int) $portalSchool === $school && str_starts_with($origin, 'https://'))) return null;
        return $origin.$path;
    }

    /** Validate again under the form lock, so an edited form or revoked guardian cannot bank a link. */
    public function link(Form $form, FormResponse $response, string $token): void
    {
        try { $claim = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw ValidationException::withMessages(['deposit' => 'Reload the deposit form from the family portal.']); }
        $school = Masjid::find($form->masjid_id);
        abort_unless(SchoolAdmissionsMode::enabled($school, 'school_reenrolment'), 403);
        if (! is_array($claim) || ($claim['expires'] ?? 0) < now()->timestamp || (int) ($claim['school'] ?? 0) !== (int) $form->masjid_id || (int) ($claim['form'] ?? 0) !== (int) $form->id) throw ValidationException::withMessages(['deposit' => 'Reload the deposit form from the family portal.']);
        $campaign = SchoolReenrolment::where('masjid_id', $form->masjid_id)->findOrFail($claim['campaign']);
        $child = GroupMembership::where('masjid_id', $form->masjid_id)->with('group', 'contact')->findOrFail($claim['membership']);
        $guardian = Contact::where('masjid_id', $form->masjid_id)->findOrFail($claim['guardian']);
        $standing = app(\App\Support\TenantContext::class)->runWithout(function () use ($guardian, $child, $form) {
            app(\App\Support\TenantContext::class)->set((int) $form->masjid_id);
            return app(GroupAudience::class)->mayReceiveAwardsAbout($guardian, $child->group, $child);
        });
        abort_unless($standing, 403);
        $answer = SchoolReenrolmentAnswer::where('masjid_id', $form->masjid_id)->where('reenrolment_id', $campaign->id)->where('student_contact_id', $child->contact_id)->value('answer');
        $entries = SchoolAdmissionIdentity::children($form, $response);
        if (! app(SchoolReenrolments::class)->open($campaign) || (int) $campaign->deposit_form_id !== (int) $form->id || $answer !== 'returning' || (SchoolAdmissionIdentity::hasChildFields($form) && (count($entries) !== 1
            || SchoolAdmissionIdentity::name($entries[0]['first_name']) !== SchoolAdmissionIdentity::name($child->contact->first_name)
            || SchoolAdmissionIdentity::name($entries[0]['last_name']) !== SchoolAdmissionIdentity::name($child->contact->last_name)))
            || ! ContactIdentity::sameAddress($response->respondent_email, $guardian->email)) throw ValidationException::withMessages(['deposit' => 'Keep this deposit for the selected child and guardian. Reopen it from the family portal to change children.']);
        if (SchoolReenrolmentDeposit::where('masjid_id', $form->masjid_id)->where('reenrolment_id', $campaign->id)->where('student_contact_id', $child->contact_id)->exists()) abort(409, self::DUPLICATE);
        try {
            SchoolReenrolmentDeposit::create(['masjid_id' => $form->masjid_id, 'reenrolment_id' => $campaign->id, 'student_contact_id' => $child->contact_id, 'form_response_id' => $response->id]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // The unique child/campaign key also covers races across different deposit forms.
            abort(409, self::DUPLICATE);
        }
    }
}
