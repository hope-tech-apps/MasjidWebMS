<?php

namespace App\Http\Requests\Family;

use App\Http\Requests\BaseFormRequest;

/**
 * A parent opening a conversation with their child's teacher.
 *
 * ## `scope` IS NOT IN THIS PAYLOAD, AND MUST NEVER BE
 *
 * The staff request accepts a scope because staff may open a class-wide thread.
 * A parent may not: a group-scoped thread reaches EVERY family in the class, and
 * one parent must not be able to start a discussion the whole room sees. The
 * controller forces SCOPE_PARTICIPANT rather than validating a value the client
 * sends, so there is no payload a parent can construct that widens the audience.
 *
 * `about_membership_id` is required and is checked in the controller against the
 * caller's OWN wards — a membership id naming another family's child is refused
 * there, by the same GroupAudience call that governs every other per-child read.
 *
 * `body` is required, unlike the staff version where it is optional. A thread a
 * parent opened with no message is a notification to a teacher with nothing in
 * it, and nothing for them to reply to.
 */
class StoreFamilyThreadRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'about_membership_id' => ['required', 'integer'],
            'body' => [
                'required', 'string',
                'max:' . (int) config('groups.messaging.max_message_length', 5000),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required' => 'Give this conversation a subject so your teacher can see what it is about.',
            'body.required' => 'Write your message.',
        ];
    }
}
