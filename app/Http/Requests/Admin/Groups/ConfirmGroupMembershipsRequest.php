<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

/**
 * Confirm the roster claims a public registration form asserted.
 *
 * `membership_ids` is REQUIRED, and that is the whole design of this request.
 *
 * It used to be optional, and an absent body meant "every pending claim in THIS
 * group at the moment the request LANDS" — which is not the same set as the one
 * the operator read. Measured: an operator loaded a roster showing eight claims,
 * an ordinary anonymous registration arrived while the confirmation dialog was
 * open, and the click returned `{"confirmed":9}`. The ninth was a stranger's
 * claim over a named child, and confirming it took his access from 403 to her
 * behaviour record, her ḥifẓ and the participant thread titled "Safeguarding:
 * incident on 3 Sept". No merge, no operator error, no race the operator could
 * have seen — the set was simply re-derived on the server after they agreed.
 *
 * Confirmation is a disclosure decision about a specific child, so the operator's
 * agreement has to BIND to what they were shown. Naming the ids is that binding,
 * and it costs the bulk affordance nothing: the client sends the ids it drew, so
 * a school's 200-signup intake is still ONE click carrying a 200-element array
 * rather than 200 dialogs. What it can no longer be is a click that means
 * "whatever is pending by the time this arrives".
 *
 * Deliberately still NOT a tenant-wide verb. The group is the smallest scope in
 * which the person clicking can see what they are vouching for — ward names, the
 * claimed guardian, and which signup asserted it are all on the screen the button
 * lives on.
 *
 * The ids are only shape-checked. Whether they exist, belong to this group, and
 * are actually pending is settled in the controller against the tenant-scoped
 * group — an `exists:` rule here would duplicate the guardrail and would confirm
 * the existence of another organisation's row in a validation message. See
 * .claude/rules/tenant-scoping.md.
 */
class ConfirmGroupMembershipsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'membership_ids' => 'required|array|min:1',
            'membership_ids.*' => 'integer',
        ];
    }

    public function messages(): array
    {
        return [
            'membership_ids.required' => 'Name the roster entries to confirm. Confirming grants access to a '
                . "child's records, so it applies only to the entries you were shown.",
            'membership_ids.min' => 'Name the roster entries to confirm.',
        ];
    }
}
