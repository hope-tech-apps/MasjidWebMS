<?php

namespace App\Http\Requests\Admin\Offerings;

use App\Models\Offering;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Update an offering.
 *
 * Every field is `sometimes`: a caller that only flips `is_active` (the
 * non-destructive way to close an offering) must not be forced to resend the
 * name — and, critically, must not have its public slug silently regenerated.
 *
 * `capacity` may be lowered below the seats already taken. That is deliberate:
 * it stops NEW registrations without revoking anyone's seat, which is exactly
 * what an admin who has run out of room means. Nothing here can write
 * `registration_count` — it is guarded on the model.
 */
class UpdateOfferingRequest extends OfferingFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('slug') && $this->filled('slug')) {
            $this->merge(['slug' => Str::slug((string) $this->input('slug'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'slug' => [
                'sometimes', 'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // Ignore this offering's own row, or renaming nothing would
                // collide with itself.
                $this->uniqueSlugRule((int) $this->route('offering_id')),
            ],
            'kind' => ['sometimes', 'required', Rule::in(Offering::KINDS)],
            'intake_form_id' => ['sometimes', 'required', 'integer', $this->ownedRule('forms')],
            'group_id' => ['sometimes', 'nullable', 'integer', $this->ownedRule('groups')],
            'capacity' => 'sometimes|nullable|integer|min:0',
            'opens_at' => 'sometimes|nullable|date',
            'closes_at' => array_values(array_filter([
                'sometimes', 'nullable', 'date',
                $this->filled('opens_at') ? 'after_or_equal:opens_at' : null,
            ])),
            'is_active' => 'sometimes|boolean',
            'settings' => 'sometimes|nullable|array',
        ];
    }
}
