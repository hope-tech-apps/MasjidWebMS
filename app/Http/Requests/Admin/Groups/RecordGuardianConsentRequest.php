<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use App\Models\GroupMembership;
use Illuminate\Validation\Rule;

/**
 * Record a guardian's consent against ONE guardian edge.
 *
 * Only the SHAPE is checked here. Whether the membership exists, and whether it
 * is actually a guardian row, is settled in GroupConsentController against the
 * tenant-scoped model — so another organization's membership id is a 404 miss
 * rather than a validation message confirming the row exists somewhere. Same
 * arrangement as StoreGroupMembershipRequest.
 *
 * `scope` is required and constrained to GroupMembership::CONSENT_SCOPES: there
 * is no "consent to everything" default, because a default is precisely what
 * consent may not be.
 */
class RecordGuardianConsentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(GroupMembership::CONSENT_SCOPES)],
            // When it was actually obtained, if the office is recording a paper
            // form signed last week. Absent means now. Never in the future — a
            // consent that has not happened yet is not a consent.
            'granted_at' => 'nullable|date|before_or_equal:now',
        ];
    }

    public function messages(): array
    {
        return [
            'scope.required' => 'Say what the guardian consented to: '
                . implode(' or ', GroupMembership::CONSENT_SCOPES) . '.',
            'scope.in' => 'That is not a consent scope this system records.',
        ];
    }
}
