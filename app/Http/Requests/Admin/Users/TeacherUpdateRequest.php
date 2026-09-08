<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\BaseFormRequest;

/**
 * Edit an existing teacher: their name, phone, and the classes they lead.
 *
 * The email is deliberately NOT here — it is the login identity, and changing it
 * is a re-invite, not an in-place edit. `class_ids` is the FULL new set (the
 * controller syncs against it); requiring at least one keeps "a teacher leads no
 * classes" from being an accidental state — removing a teacher entirely is the
 * DELETE action, not an empty update.
 */
class TeacherUpdateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ]+$/'],
            'class_ids' => ['required', 'array', 'min:1'],
            'class_ids.*' => ['integer'],
        ];
    }
}
