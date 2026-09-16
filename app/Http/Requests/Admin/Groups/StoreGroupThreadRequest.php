<?php

namespace App\Http\Requests\Admin\Groups;

use App\Models\GroupThread;
use Illuminate\Validation\Rule;

/**
 * Open a messaging thread in a group (T-005c).
 *
 * The scope invariant is enforced in BOTH directions, exactly like the
 * guardian/ward pair on StoreGroupMembershipRequest:
 *   - scope = participant REQUIRES about_membership_id (a private conversation
 *     "about nobody in particular" is the ambiguity the column removes);
 *   - scope = group PROHIBITS it, so a target can never be smuggled onto a
 *     group-wide thread where nothing would read it.
 *
 * The id is only shape-checked here. Whether it names a PARTICIPANT of THIS
 * group is settled in GroupThreadsController through the tenant-scoped
 * membership relation, so another organization's membership id is a miss —
 * re-implementing the tenant filter in an `exists:` rule would duplicate the
 * guardrail (.claude/rules/tenant-scoping.md).
 *
 * `body` and `images` optionally carry the thread's first message, written in
 * the same transaction — a conversation usually opens with something to say,
 * but an empty thread is legal (an office preparing a channel before term
 * starts). The photos are held to the class story's rules; see
 * GroupPostFormRequest, which this extends for exactly that.
 *
 * `retained_until` mirrors StoreGroupPostRequest: optional, because the model
 * stamps the configured window when it is absent; after_or_equal:today because
 * a window already in the past is a mistake, not an instruction.
 *
 * masjid_id is NOT accepted and never will be — the BelongsToMasjid creating
 * hook stamps it from the bound tenant.
 */
class StoreGroupThreadRequest extends GroupPostFormRequest
{
    public function rules(): array
    {
        return array_merge([
            'subject' => 'required|string|max:255',
            'scope' => ['required', Rule::in(GroupThread::SCOPES)],
            'about_membership_id' => [
                'required_if:scope,' . GroupThread::SCOPE_PARTICIPANT,
                'prohibited_unless:scope,' . GroupThread::SCOPE_PARTICIPANT,
                'nullable', 'integer',
            ],
            'body' => 'nullable|string|max:' . (int) config('groups.messaging.max_message_length', 5000),
            'retained_until' => 'nullable|date|after_or_equal:today',
        ], $this->imageRules());
    }

    protected function uploadNoun(): string
    {
        return 'message';
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'about_membership_id.required_if' =>
                'A participant-scoped thread must name the membership of the member it concerns.',
            'about_membership_id.prohibited_unless' =>
                'Only a participant-scoped thread may name a member it concerns.',
        ]);
    }
}
