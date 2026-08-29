<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a teacher login and assign the classes they lead, in one step.
 *
 * Deliberately does NOT accept a `type` and does NOT use the shared
 * `UserTypeRule`: the type is forced to 'Teacher' server-side (TeachersController),
 * never from input. Widening UserTypeRule to admit 'Teacher' would also open the
 * general Store/Update user forms to minting teachers, which must stay a
 * deliberate, class-scoped action — a MasjidAdmin can create a teacher for their
 * OWN school's classes and nothing more.
 */
class TeacherInviteRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Archived users are ignored so a retired address can be reused,
            // matching InviteUserRequest.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ]+$/'],
            // At least one class — a teacher with no classes has nothing to sign
            // in for. That the ids name classes IN THE BOUND SCHOOL is verified in
            // the controller against the tenant-scoped Group query, not here (a
            // plain exists rule cannot see the tenant scope).
            'class_ids' => ['required', 'array', 'min:1'],
            'class_ids.*' => ['integer'],
        ];
    }
}
