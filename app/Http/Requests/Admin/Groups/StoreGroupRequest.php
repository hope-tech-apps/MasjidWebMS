<?php

namespace App\Http\Requests\Admin\Groups;

use App\Models\Group;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Create a group.
 */
class StoreGroupRequest extends GroupFormRequest
{
    /**
     * Derive the slug before validation rather than in the controller, so the
     * value that gets uniqueness-checked is exactly the value that gets stored —
     * the same reasoning as ProvisionMasjidRequest normalizing org_type here.
     */
    protected function prepareForValidation(): void
    {
        $supplied = $this->input('slug');
        $source = is_string($supplied) && trim($supplied) !== ''
            ? $supplied
            : (string) $this->input('name');

        $this->merge([
            'slug' => Str::slug($source),
            // Absent or empty kind means "just a group". Defaulted HERE, not in
            // the controller, so validation and persistence cannot disagree —
            // and so a literal null never reaches a NOT NULL column.
            'kind' => $this->filled('kind') ? $this->input('kind') : Group::KIND_GENERAL,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $this->uniqueSlugRule(),
            ],
            'kind' => ['required', Rule::in(Group::KINDS)],
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'starts_on' => 'nullable|date',
            // Ordering is only checkable when both ends of the window were sent;
            // `after_or_equal` against an absent field cannot decide anything.
            'ends_on' => array_values(array_filter([
                'nullable', 'date',
                $this->filled('starts_on') ? 'after_or_equal:starts_on' : null,
            ])),
        ];
    }
}
