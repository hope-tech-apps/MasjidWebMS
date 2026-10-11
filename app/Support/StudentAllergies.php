<?php

namespace App\Support;

use App\Models\{Contact, Form, FormResponse, Group, GroupMembership, Masjid, StudentAllergy};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentAllergies
{
    public static function normalise(?string $answer): ?string
    {
        $answer = preg_replace('/^\s+|\s+$/u', '', $answer ?? '');
        $comparison = mb_strtolower(preg_replace('/^[\s\p{P}\p{S}]+|[\s\p{P}\p{S}]+$/u', '', $answer));
        return in_array($comparison, ['', 'none', 'no', 'n/a', 'na', 'nil', 'nothing', 'no allergies', 'none known'], true) ? null : $answer;
    }

    public static function enabled(?Masjid $school): bool
    {
        return $school !== null && $school->deleted_at === null && $school->isSchool() && $school->hasCapability('student_allergies');
    }

    /** Exactly one notes query for a class; no schema/capability SQL on the OFF HTTP path. */
    public static function forRoster(Group $group, Collection $memberships, bool $teacher = false): ?array
    {
        if (! $group->teachesStudents() || ! ClassSubjectMode::allergiesEnabled($group->masjid_id)) return null;
        $ids = $memberships->filter(fn ($m) => $m->role === GroupMembership::ROLE_MEMBER && (! $teacher || $m->left_on === null))->pluck('contact_id');
        $records = StudentAllergy::query()->where('masjid_id', $group->masjid_id)->whereIn('contact_id', $ids)->get()->keyBy('contact_id');
        $payloads = [];
        foreach ($memberships as $m) {
            if ($m->role !== GroupMembership::ROLE_MEMBER || ($teacher && $m->left_on !== null)) continue;
            $record = $records->get($m->contact_id);
            $payloads[(int) $m->id] = $record === null ? null : ($teacher ? ['allergies' => $record->allergies] : $record->officePayload());
        }
        return $payloads;
    }

    public static function edit(Contact $student, ?string $answer, ?int $actor): StudentAllergy
    {
        return DB::transaction(function () use ($student, $answer, $actor) {
            Contact::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            return StudentAllergy::updateOrCreate(['masjid_id' => $student->masjid_id, 'contact_id' => $student->id], [
                'allergies' => self::normalise($answer), 'source' => 'office', 'form_response_id' => null, 'changed_by_user_id' => $actor,
            ]);
        });
    }

    private static function name(mixed $value): string
    {
        return is_string($value) ? mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value))) : '';
    }

    private static function email(mixed $value): string
    {
        return is_string($value) ? mb_strtolower(trim($value)) : '';
    }

    private static function phone(mixed $value): string
    {
        return is_string($value) ? preg_replace('/\D/', '', $value) : '';
    }

    /** No stored response-child/contact link exists. Match exact names within the guardian's household. */
    public function copy(Form $form, string $field, bool $dryRun, ?int $actor): array
    {
        $section = $form->repeatableSection();
        $fields = collect($section['fields'] ?? collect($form->sections())->flatMap(fn ($s) => $s['fields'] ?? [])->all())->pluck('name')->all();
        $first = $section ? 'firstName' : 'studentFirstName';
        $last = $section ? 'lastName' : 'studentLastName';
        if (! in_array($field, $fields, true) || ! in_array($first, $fields, true) || ! in_array($last, $fields, true)) {
            throw ValidationException::withMessages(['field' => 'Choose a child field on a form with first and last names.']);
        }

        // All identities are compared byte-exact in PHP after normalisation, independent of DB collation.
        $students = Contact::query()->where('masjid_id', $form->masjid_id)->whereHas('groupMemberships', fn ($q) => $q->where('role', 'member')->whereHas('group', fn ($g) => $g->where('kind', 'class')))->get(['id', 'first_name', 'last_name']);
        $guardians = GroupMembership::query()->where('masjid_id', $form->masjid_id)->where('role', 'guardian')->whereIn('guardian_of_contact_id', $students->pluck('id'))->with('contact:id,email,phone')->get();
        $households = [];
        foreach ($guardians as $g) {
            if ($g->contact) $households[$g->guardian_of_contact_id][] = [self::email($g->contact->email), self::phone($g->contact->phone)];
        }
        $counts = array_fill_keys(['children_read', 'with_allergy', 'matched', 'already_set', 'unmatched', 'ambiguous', 'office_wins', 'written', 'invalid', 'empty', 'superseded'], 0);
        $children = [];
        $processed = [];
        $responses = FormResponse::query()->where('masjid_id', $form->masjid_id)->where('form_id', $form->id)->where('status', '!=', 'cancelled')->orderByDesc('id')->get();
        foreach ($responses as $response) {
            $entries = $section ? ($response->data[$section['id']] ?? []) : [$response->data ?? []];
            if (! is_array($entries)) continue;
            foreach ($entries as $index => $entry) {
                if (! is_array($entry)) continue;
                $counts['children_read']++;
                $row = ['response_id' => (int) $response->id, 'entry_index' => $index, 'name' => trim((is_string($entry[$first] ?? null) ? $entry[$first] : '').' '.(is_string($entry[$last] ?? null) ? $entry[$last] : ''))];
                $raw = $entry[$field] ?? null;
                if ($raw !== null && ! is_string($raw)) { $counts['invalid']++; $children[] = $row + ['outcome' => 'invalid']; continue; }
                $answer = self::normalise($raw);
                if ($answer !== null) $counts['with_allergy']++;
                if ($answer !== null && mb_strlen($answer) > 500) {
                    $counts['invalid']++; $children[] = $row + ['outcome' => 'too_long']; continue;
                }
                $email = self::email($response->respondent_email);
                $phone = self::phone($response->respondent_phone);
                $matches = $students->filter(function ($s) use ($entry, $first, $last, $email, $phone, $households) {
                    if (self::name($entry[$first] ?? null) === '' || self::name($entry[$last] ?? null) === '') return false;
                    if (self::name($s->first_name) !== self::name($entry[$first]) || self::name($s->last_name) !== self::name($entry[$last])) return false;
                    foreach ($households[$s->id] ?? [] as [$guardianEmail, $guardianPhone]) {
                        if ($email !== '' ? $guardianEmail === $email : ($phone !== '' && $guardianPhone === $phone)) return true;
                    }
                    return false;
                });
                if ($matches->count() !== 1) {
                    $outcome = $matches->isEmpty() ? 'unmatched' : 'ambiguous';
                    $counts[$outcome]++; $children[] = $row + ['outcome' => $outcome]; continue;
                }
                $counts['matched']++;
                $student = $matches->first();
                if (isset($processed[$student->id])) {
                    $counts['superseded']++; $children[] = $row + ['outcome' => 'superseded', 'contact_id' => (int) $student->id]; continue;
                }
                $processed[$student->id] = true;
                $outcome = DB::transaction(function () use ($student, $form, $response, $answer, $dryRun, $actor) {
                    $query = Contact::query()->where('masjid_id', $form->masjid_id)->whereKey($student->id);
                    if (! $dryRun) $query->lockForUpdate();
                    $query->firstOrFail();
                    $existing = StudentAllergy::query()->where('masjid_id', $form->masjid_id)->where('contact_id', $student->id)->first();
                    if ($existing?->source === 'office') return 'office_wins';
                    if (! $existing && $answer === null) return 'empty';
                    if ($existing && $existing->allergies === $answer) return 'already_set';
                    $values = ['masjid_id' => $form->masjid_id, 'contact_id' => $student->id, 'allergies' => $answer, 'source' => 'form', 'form_response_id' => $response->id, 'changed_by_user_id' => $actor];
                    if (! $dryRun) StudentAllergy::updateOrCreate(['masjid_id' => $form->masjid_id, 'contact_id' => $student->id], $values);
                    return 'written';
                });
                $counts[$outcome]++;
                $children[] = $row + ['outcome' => $outcome, 'contact_id' => (int) $student->id];
            }
        }
        return ['dry_run' => $dryRun, 'counts' => $counts, 'children' => $children, 'message' => 'Office edits, including clears, win. No answers are copied automatically.'];
    }
}
