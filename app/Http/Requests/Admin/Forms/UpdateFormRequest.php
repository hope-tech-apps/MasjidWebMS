<?php

namespace App\Http\Requests\Admin\Forms;

use Illuminate\Validation\Rule;

/**
 * Same shape as StoreFormRequest, with every field optional (`sometimes`) so the
 * builder can PATCH just the schema, or just the window, without resending everything.
 *
 * Extends the store request so the schema structure rule, the identity/fee cross-checks
 * and the JSON-string coercion stay in exactly one place — a form that is valid to
 * create must remain valid to edit.
 */
class UpdateFormRequest extends StoreFormRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        // Ignore this form's own row when checking slug uniqueness.
        $rules['slug'] = [
            'sometimes',
            'required',
            'string',
            'max:255',
            'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::unique('forms', 'slug')
                ->where('masjid_id', (int) $this->route('masjid_id'))
                ->whereNull('deleted_at')
                ->ignore((int) $this->route('form_id')),
        ];

        foreach (['name', 'schema'] as $key) {
            if (isset($rules[$key]) && is_array($rules[$key])) {
                array_unshift($rules[$key], 'sometimes');
            } elseif (isset($rules[$key])) {
                $rules[$key] = 'sometimes|' . $rules[$key];
            }
        }

        return $rules;
    }

    /**
     * The cross-field checks in the parent read `schema` off the request. On a partial
     * update that omits `schema`, there is nothing to check against — skip rather than
     * reject, since the stored schema is already known-valid.
     */
    public function withValidator($validator): void
    {
        if (! is_array($this->input('schema'))) {
            return;
        }

        parent::withValidator($validator);
    }
}
