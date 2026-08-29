<?php

namespace App\Http\Requests\Admin\Offerings;

use App\Models\Offering;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Create an offering.
 *
 * `intake_form_id` is REQUIRED: the column is NOT NULL because every
 * registration validates through the offering's stored FormSchema — an
 * offering without an intake form cannot take a registration at all
 * (.claude/rules/registration-billing-data.md).
 */
class StoreOfferingRequest extends OfferingFormRequest
{
    /**
     * Derive the slug before validation rather than in the controller, so the
     * value that gets uniqueness-checked is exactly the value that gets stored
     * (the StoreGroupRequest idiom).
     */
    protected function prepareForValidation(): void
    {
        $supplied = $this->input('slug');
        $source = is_string($supplied) && trim($supplied) !== ''
            ? $supplied
            : (string) $this->input('name');

        $this->merge([
            'slug' => Str::slug($source),
            // Absent kind means the plainest registerable thing. Defaulted HERE
            // so validation and persistence cannot disagree, and so a literal
            // null never reaches a NOT NULL column.
            'kind' => $this->filled('kind') ? $this->input('kind') : Offering::KIND_EVENT,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            // Public copy (T-006g) — served verbatim to anonymous visitors by
            // OfferingPublicPayload. Optional: an offering with no description
            // is a legitimate state and the renderer omits the block.
            'description' => 'nullable|string|max:5000',
            'slug' => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $this->uniqueSlugRule(),
            ],
            'kind' => ['required', Rule::in(Offering::KINDS)],
            'intake_form_id' => ['required', 'integer', $this->ownedRule('forms')],
            // Nullable: an offering may collect money without building a roster.
            'group_id' => ['nullable', 'integer', $this->ownedRule('groups')],
            // Null capacity is unlimited (the forms convention).
            'capacity' => 'nullable|integer|min:0',
            'opens_at' => 'nullable|date',
            // Ordering is only checkable when both ends of the window were sent.
            'closes_at' => array_values(array_filter([
                'nullable', 'date',
                $this->filled('opens_at') ? 'after_or_equal:opens_at' : null,
            ])),
            'is_active' => 'sometimes|boolean',
            'settings' => 'nullable|array',
        ];
    }
}
