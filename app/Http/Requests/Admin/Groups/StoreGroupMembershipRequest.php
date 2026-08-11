<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use App\Models\GroupMembership;
use Illuminate\Validation\Rule;

/**
 * Add someone to a group.
 *
 * The ids are only shape-checked here. Whether they EXIST is settled in the
 * controller with the tenant-scoped Contact::findOrFail(), so another masjid's
 * contact is a 404 miss rather than a validation message that would confirm the
 * row exists somewhere. Re-implementing the tenant filter in an `exists:` rule
 * would duplicate the guardrail — see .claude/rules/tenant-scoping.md.
 *
 * The guardianship invariant is enforced in BOTH directions:
 *   - role = guardian REQUIRES guardian_of_contact_id (a guardian of nobody is
 *     the ambiguity this column exists to remove);
 *   - any other role PROHIBITS it, so a ward can never be smuggled onto a
 *     leader/member row where nothing would read it.
 */
class StoreGroupMembershipRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'contact_id' => 'required|integer',
            'role' => ['required', Rule::in(GroupMembership::ROLES)],
            'guardian_of_contact_id' => [
                'prohibited_unless:role,' . GroupMembership::ROLE_GUARDIAN,
                'required_if:role,' . GroupMembership::ROLE_GUARDIAN,
                'nullable', 'integer',
                // A person cannot be their own guardian.
                'different:contact_id',
            ],
            'joined_at' => 'nullable|date',
        ];
    }

    public function messages(): array
    {
        return [
            'guardian_of_contact_id.prohibited_unless' =>
                'Only a guardian membership may name the member it is attached to.',
            'guardian_of_contact_id.required_if' =>
                'A guardian membership must name the member it is a guardian of.',
            'guardian_of_contact_id.different' =>
                'A member cannot be their own guardian.',
        ];
    }
}
