<?php

namespace App\Support;

use App\Models\{Contact, Form, FormResponse, Group, GroupMembership, Masjid, Registration, Registrant, StudentAllergy};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentAllergies
{
    public static function normalise(?string $answer): ?string
    {
        $answer = preg_replace('/^\s+|\s+$/u', '', $answer ?? '');
        $comparison = mb_strtolower(preg_replace('/^[\s\x21-\x23\x25-\x2a\x2c-\x2f\x3a-\x3b\x3f-\x40\x5b-\x5d\x5f\x7b\x7d]+|[\s\x21-\x23\x25-\x2a\x2c-\x2f\x3a-\x3b\x3f-\x40\x5b-\x5d\x5f\x7b\x7d]+$/u', '', $answer));
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
            $payloads[(int) $m->id] = $record === null ? null : $record->payload($teacher);
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

    /** Cascades fire no health-model events; contact erasure refreshes the durable hint too. */
    public static function refreshRetention(int $school): void
    {
        DB::transaction(function () use ($school) {
            DB::table('masjids')->where('id', $school)->lockForUpdate()->first();
            $held = DB::table('student_allergies')->where('masjid_id', $school)->exists();
            DB::table('masjids')->where('id', $school)->update(['has_student_allergy_records' => $held]);
            ClassSubjectMode::forget($school);
        });
    }

    /** Called inside the merge transaction, independent of the disclosure switch. */
    public static function merge(Contact $source, Contact $target, ?int $actor): void
    {
        if (! ClassSubjectMode::allergyRecordsHeld((int) $source->masjid_id)) return;
        Contact::query()->whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get();
        $records = StudentAllergy::query()->where('masjid_id', $source->masjid_id)->whereIn('contact_id', [$source->id, $target->id])->lockForUpdate()->get()->keyBy('contact_id');
        $absorbed = $records->get($source->id);
        if ($absorbed === null) return;
        // Decryption failure refuses the merge, so a record can never cascade away unread.
        $absorbedPayload = $absorbed->payload();
        $survivor = $records->get($target->id);
        $survivorPayload = $survivor?->payload();
        if (($absorbedPayload['unavailable'] ?? false) || ($survivorPayload['unavailable'] ?? false)) throw ValidationException::withMessages(['allergies' => 'An allergy note is unavailable. Ask the office to restore it before merging.']);
        $text = $absorbedPayload['allergies'];
        $kept = $survivorPayload['allergies'] ?? null;
        if ($text === null) return;
        if ($kept === null) {
            if ($survivor !== null) $survivor->delete();
            $absorbed->forceFill(['contact_id' => $target->id, 'changed_by_user_id' => $actor])->save();
        } elseif ($kept !== $text) {
            $combined = $kept.' / '.$text;
            if (mb_strlen($combined) > 500) throw ValidationException::withMessages(['allergies' => 'Both records have allergy notes too long to combine. Shorten one, then merge.']);
            $survivor->forceFill(['allergies' => $combined, 'source' => 'office', 'form_response_id' => null, 'changed_by_user_id' => $actor])->save();
        }
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

    /** Select a child section by fields, never by the mere presence of a repeatable section. */
    public static function childSection(Form $form, ?string $field = null): ?array
    {
        foreach ($form->sections() as $section) {
            $fields = collect($section['fields'] ?? [])->pluck('name');
            if (! empty($section['repeatable']) && $fields->contains('firstName') && $fields->contains('lastName') && ($field === null || $fields->contains($field))) return $section;
        }
        return null;
    }

    /** Stored registrant links precede exact names within the guardian's household. */
    public function copy(Form $form, string $field, bool $dryRun, ?int $actor): array
    {
        $section = self::childSection($form, $field);
        $fields = collect($section['fields'] ?? collect($form->sections())->reject(fn ($s) => ! empty($s['repeatable']))->flatMap(fn ($s) => $s['fields'] ?? [])->all())->pluck('name')->all();
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
        $counts = array_fill_keys(['children_read', 'with_allergy', 'matched', 'already_set', 'unmatched', 'ambiguous', 'office_wins', 'written', 'invalid', 'empty', 'superseded', 'blank_answer', 'too_long'], 0);
        $children = [];
        $processed = [];
        $responses = FormResponse::query()->where('masjid_id', $form->masjid_id)->where('form_id', $form->id)->where('status', '!=', 'cancelled')->orderByDesc('id')->get();
        $registrations = Registration::query()->where('masjid_id', $form->masjid_id)->whereIn('form_response_id', $responses->pluck('id'))->where('status', '!=', 'cancelled')->get(['id', 'form_response_id']);
        $registrants = Registrant::query()->where('masjid_id', $form->masjid_id)->whereIn('registration_id', $registrations->pluck('id'))->get(['registration_id', 'contact_id']);
        $linkedContacts = Contact::query()->where('masjid_id', $form->masjid_id)->whereIn('id', $registrants->pluck('contact_id'))->get(['id', 'first_name', 'last_name']);
        $links = [];
        foreach ($registrations as $registration) {
            foreach ($registrants->where('registration_id', $registration->id) as $registrant) $links[$registration->form_response_id][] = $registrant->contact_id;
        }
        foreach ($responses as $response) {
            $entries = $section ? ($response->data[$section['id']] ?? []) : [$response->data ?? []];
            if (! is_array($entries)) continue;
            foreach ($entries as $index => $entry) {
                if (! is_array($entry)) continue;
                $counts['children_read']++;
                $row = ['response_id' => (int) $response->id, 'entry_index' => $index, 'name' => trim((is_string($entry[$first] ?? null) ? $entry[$first] : '').' '.(is_string($entry[$last] ?? null) ? $entry[$last] : ''))];
                $raw = $entry[$field] ?? null;
                if ($raw !== null && ! is_string($raw)) { $counts['invalid']++; $children[] = $row + ['outcome' => 'invalid']; continue; }
                if ($raw === null || preg_match('/^\s*$/u', $raw)) {
                    $counts['blank_answer']++; $children[] = $row + ['outcome' => 'left unchanged: blank answer']; continue;
                }
                $answer = self::normalise($raw);
                if ($answer !== null) $counts['with_allergy']++;
                // 8,000 four-byte code points encrypt to under 65,535 TEXT bytes.
                $tooLong = $answer !== null && mb_strlen($answer) > 8000;
                if ($tooLong) $answer = 'See enrolment form: answer too long';
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
                $linked = $linkedContacts->whereIn('id', array_unique($links[$response->id] ?? []));
                if ($linked->count() === 1 && count($entries) === 1) {
                    $matches = $students->whereIn('id', $linked->pluck('id'));
                } elseif ($linked->isNotEmpty()) {
                    // Multiple children require a unique exact name on both sides of the link.
                    $named = $linked->filter(fn ($s) => self::name($s->first_name) === self::name($entry[$first] ?? null) && self::name($s->last_name) === self::name($entry[$last] ?? null) && self::name($s->first_name) !== '' && self::name($s->last_name) !== '');
                    $sameEntries = collect($entries)->filter(fn ($e) => is_array($e) && self::name($e[$first] ?? null) === self::name($entry[$first] ?? null) && self::name($e[$last] ?? null) === self::name($entry[$last] ?? null));
                    if ($named->count() > 1) {
                        $counts['ambiguous']++; $children[] = $row + ['outcome' => 'ambiguous']; continue;
                    }
                    if ($named->count() === 1 && $sameEntries->count() === 1) $matches = $students->whereIn('id', $named->pluck('id'));
                }
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
                if ($tooLong && $outcome !== 'office_wins') {
                    $counts['too_long']++;
                    $children[] = $row + ['outcome' => 'too long: enter by hand', 'contact_id' => (int) $student->id];
                }
                $counts[$outcome]++;
                $children[] = $row + ['outcome' => $outcome, 'contact_id' => (int) $student->id];
            }
        }
        return ['dry_run' => $dryRun, 'counts' => $counts, 'children' => $children, 'message' => 'Office edits, including clears, win. No answers are copied automatically.'];
    }
}
