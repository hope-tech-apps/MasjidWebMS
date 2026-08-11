<?php

namespace App\Http\Requests\Admin\Groups;

use App\Models\Group;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Update a group.
 *
 * Every field is `sometimes`: a caller that only flips `is_active` must not be
 * forced to resend the name, and — critically — must not have its slug silently
 * regenerated. The slug is only re-derived when the caller actually sends one.
 */
class UpdateGroupRequest extends GroupFormRequest
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
                // Ignore this group's own row, or renaming nothing would collide
                // with itself.
                $this->uniqueSlugRule((int) $this->route('group_id')),
            ],
            'kind' => ['sometimes', 'required', Rule::in(Group::KINDS)],
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
            'starts_on' => 'sometimes|nullable|date',
            // Ordering is only checkable when the caller sent both ends of the
            // window; `after_or_equal` against an absent field silently compares
            // to nothing, so it is added only when it can actually decide.
            'ends_on' => array_values(array_filter([
                'sometimes', 'nullable', 'date',
                $this->filled('starts_on') ? 'after_or_equal:starts_on' : null,
            ])),
        ];
    }
}
