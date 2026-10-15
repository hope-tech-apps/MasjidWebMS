<?php

declare(strict_types=1);

namespace App\Services\Schools;

use App\Models\{Contact, Form, FormResponse, GroupMembership, Registration, Registrant};
use App\Support\{ContactIdentity, FormRoster, GradeLevel, StudentAllergies};
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Child rows come from Forms' roster projection; an email identifies an adult, never a child. */
final class SchoolAdmissionIdentity
{
    public static function name(mixed $value): string
    {
        return is_string($value) ? mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value))) : '';
    }

    public static function hasChildFields(Form $form): bool
    {
        if (StudentAllergies::childSection($form) !== null) return true;
        $fields = collect($form->sections())->reject(fn ($s) => ! empty($s['repeatable']))->flatMap(fn ($s) => $s['fields'] ?? [])->pluck('name');
        return $fields->contains('studentFirstName') && $fields->contains('studentLastName');
    }

    public static function children(Form $form, FormResponse $response): array
    {
        $section = StudentAllergies::childSection($form);
        $first = $section ? 'firstName' : 'studentFirstName'; $last = $section ? 'lastName' : 'studentLastName';
        // A repeatable emergency-contact section is not a list of children.
        if ($section && ($form->repeatableSection()['id'] ?? null) === $section['id']) $rows = FormRoster::for($form)->rows(collect([$response]));
        elseif ($section) $rows = collect($response->data[$section['id']] ?? [])->map(fn ($values, $index) => ['values' => $values, 'entry_index' => $index]);
        else $rows = collect([['values' => $response->data ?? [], 'entry_index' => 0]]);
        $fields = $section ? $section['fields'] ?? [] : collect($form->sections())->reject(fn ($s) => ! empty($s['repeatable']))->flatMap(fn ($s) => $s['fields'] ?? [])->all();
        $applyingKeys = collect($fields)->filter(fn ($f) => preg_match('/^grade applying/i', trim($f['label'] ?? '')))->pluck('name')->all();
        return $rows->map(function ($row) use ($first, $last, $applyingKeys) {
            $values = $row['values'];
            $grade = '';
            foreach (array_unique(['gradeApplyingFor', 'applyingGrade', 'nextGrade', ...$applyingKeys, 'grade', 'schoolGrade', 'gradeLevel', 'studentGrade', 'currentGrade']) as $key) {
                if (is_scalar($values[$key] ?? null) && trim((string) $values[$key]) !== '') { $grade = trim((string) $values[$key]); break; }
            }
            $position = GradeLevel::position($grade);
            return ['entry_index' => (int) $row['entry_index'], 'first_name' => trim((string) ($values[$first] ?? '')), 'last_name' => trim((string) ($values[$last] ?? '')),
                'grade' => $position === null ? $grade : GradeLevel::LEVELS[$position]];
        })->all();
    }

    /** Identity-map respondent plus explicitly named parent/guardian email fields outside child rows. */
    public static function guardians(Form $form, FormResponse $response): array
    {
        $result = [];
        $add = function ($email, $name = '') use (&$result) {
            if (! is_string($email)) return;
            $normal = ContactIdentity::submittedAddress($email);
            if ($normal === null || ! filter_var($normal, FILTER_VALIDATE_EMAIL)) return;
            $result[$normal] = ['email' => $normal, 'name' => is_string($name) && trim($name) !== '' ? trim($name) : 'Guardian'];
        };
        $add($response->respondent_email, $response->respondent_name);
        foreach ($form->sections() as $section) {
            if (! empty($section['repeatable'])) continue;
            foreach ($section['fields'] ?? [] as $field) {
                $key = $field['name'] ?? '';
                if (($field['type'] ?? '') !== 'email' || ! preg_match('/^(parent\d*|guardian\d*)Email$/i', $key, $m)) continue;
                $prefix = $m[1];
                $name = $response->data[$prefix.'Name'] ?? $response->data[$prefix.'FullName'] ?? trim(($response->data[$prefix.'FirstName'] ?? '').' '.($response->data[$prefix.'LastName'] ?? ''));
                $add($response->data[$key] ?? null, $name);
            }
        }
        return array_values($result);
    }

    public static function guardianContacts(Form $form, FormResponse $response): Collection
    {
        return collect(self::guardians($form, $response))->flatMap(fn ($g) => ContactIdentity::keepExactMatches(
            Contact::where('masjid_id', $form->masjid_id)->whereRaw('LOWER(TRIM(email)) = ?', [$g['email']])->get(), 'email', $g['email']
        ))->unique('id')->values();
    }

    /** Stored links win; otherwise both names must match inside these adults' recorded household. */
    public static function childMatches(Form $form, FormResponse $response, array $child): Collection
    {
        if (self::name($child['first_name']) === '' || self::name($child['last_name']) === '') throw ValidationException::withMessages(['child' => 'The form needs a child first and last name before adding to a roster.']);
        $registrations = Registration::where('masjid_id', $form->masjid_id)->where('form_response_id', $response->id)->pluck('id');
        $linked = Registrant::where('masjid_id', $form->masjid_id)->whereIn('registration_id', $registrations)->pluck('contact_id');
        $matchesName = fn ($c) => self::name($c->first_name) === self::name($child['first_name']) && self::name($c->last_name) === self::name($child['last_name']);
        $direct = Contact::where('masjid_id', $form->masjid_id)->whereIn('id', $linked)->get()->filter($matchesName);
        if ($direct->isNotEmpty()) return $direct->values();
        $adults = self::guardianContacts($form, $response)->pluck('id');
        $household = GroupMembership::where('masjid_id', $form->masjid_id)->where('role', 'guardian')->whereIn('contact_id', $adults)->pluck('guardian_of_contact_id');
        return Contact::where('masjid_id', $form->masjid_id)->whereIn('id', $household)->get()->filter($matchesName)->values();
    }
}
