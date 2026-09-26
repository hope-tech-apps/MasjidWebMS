<?php

namespace App\Http\Requests\Admin\Contacts;

use App\Http\Requests\BaseFormRequest;
use App\Models\ContactTag;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Create or rename a contact tag.
 *
 * "Already exists" is decided on ContactTag::keyFor(), the same key the unique
 * index holds, so "Volunteer" and " volunteer" are refused here with a sentence
 * rather than reaching the index as a 500. The organisation is the BOUND tenant
 * (.claude/rules/tenant-scoping.md: read the bound tenant first), never a value
 * from the body.
 */
class SaveContactTagRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => ContactTag::cleanName($this->input('name')),
            'name_key' => ContactTag::keyFor($this->input('name')),
        ]);
    }

    public function rules(): array
    {
        $masjidId = app(TenantContext::class)->get() ?? (int) $this->route('masjid_id');

        return [
            'name' => ['required', 'string', 'max:' . ContactTag::NAME_MAX],
            'name_key' => [
                Rule::unique('contact_tags', 'name_key')
                    ->where(fn ($query) => $query->where('masjid_id', $masjidId))
                    ->ignore($this->route('tag_id')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name_key.unique' => 'A tag with this name already exists.',
        ];
    }
}
