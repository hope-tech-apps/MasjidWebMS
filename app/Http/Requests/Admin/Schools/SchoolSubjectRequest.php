<?php

namespace App\Http\Requests\Admin\Schools;

use App\Http\Requests\BaseFormRequest;
use App\Support\GradeLevel;
use App\Support\SubjectKey;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Add or edit a subject on the school's list (T-001.3).
 *
 * "Already on the list" is decided on SubjectKey, the key the unique index holds,
 * so "Qur’an" and "Qur'an" are refused with a sentence and never reach the index
 * as a 500. The school is the BOUND tenant, never a value from the body.
 *
 * `grade_labels` is either absent/empty (every grade) or a list of the levels the
 * screen offers (GradeLevel::LEVELS), never free text: a typo there would hide a
 * subject from a whole grade, silently.
 *
 * Form-encoded clients send an empty list as nothing at all, so an absent
 * `grade_labels` on an EDIT means "every grade", not "leave as is". The screen
 * always sends the full set, and the update replaces it.
 */
class SchoolSubjectRequest extends BaseFormRequest
{
    public const NAME_MAX = 64;

    protected function prepareForValidation(): void
    {
        $labels = $this->input('grade_labels');

        $this->merge([
            'name' => SubjectKey::clean(is_string($this->input('name')) ? $this->input('name') : null),
            'name_key' => SubjectKey::for(is_string($this->input('name')) ? $this->input('name') : null),
            // Empty means every grade; a scalar is a malformed list and must fail.
            'grade_labels' => is_array($labels) && $labels === [] ? null : $labels,
        ]);
    }

    public function rules(): array
    {
        $masjidId = app(TenantContext::class)->get() ?? (int) $this->route('masjid_id');

        return [
            'name' => ['required', 'string', 'max:' . self::NAME_MAX],
            'name_key' => [
                Rule::unique('school_subjects', 'name_key')
                    ->where(fn ($query) => $query->where('masjid_id', $masjidId))
                    ->ignore($this->route('subject_id')),
            ],
            'grade_labels' => ['nullable', 'array', 'max:' . count(GradeLevel::LEVELS)],
            'grade_labels.*' => ['string', Rule::in(GradeLevel::LEVELS), 'distinct'],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name_key.unique' => 'That subject is already on the list.',
            'grade_labels.*.in' => 'Choose grade levels from the list.',
        ];
    }
}
