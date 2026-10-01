<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use App\Support\LineEndings;
use App\Models\GroupThread;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Write a NEW conversation now and have it opened later (T-002.4).
 *
 * The same shape as StoreGroupThreadRequest (subject, scope, and for a participant
 * conversation the member it concerns, with the same both-directions rule), plus a
 * REQUIRED `send_at`, and with two deliberate differences:
 *
 *   - `body` is REQUIRED. A conversation that is opened empty says nothing, and a
 *     schedule for saying nothing is a mistake.
 *   - NO PHOTOS in v1 (S13: "messages text-only in v1"). A scheduled photo would have
 *     to be held on the private disk for up to 30 days against a schedule that may be
 *     cancelled, with a retention and cleanup story of its own; that is a separate
 *     decision. The file bags are refused, not silently dropped, so a client that
 *     believes it scheduled a photo is told it did not.
 *
 * `send_at` is the SCHOOL's own wall clock, in the future, at most 30 days ahead
 * (ValidatesSendAt). There is no "send now" here: that is a message, and the thread
 * endpoint already opens one.
 */
class StoreScheduledMessageRequest extends BaseFormRequest
{
    use ValidatesSendAt;

    /** The text is a message once it goes out, so it is stored with the one line ending a message has (LineEndings). */
    protected function prepareForValidation(): void
    {
        $this->merge(LineEndings::normalised($this, ['body']));
    }

    public function rules(): array
    {
        return [
            'subject' => 'required|string|max:255',
            'scope' => ['required', Rule::in(GroupThread::SCOPES)],
            'about_membership_id' => [
                'required_if:scope,' . GroupThread::SCOPE_PARTICIPANT,
                'prohibited_unless:scope,' . GroupThread::SCOPE_PARTICIPANT,
                'nullable', 'integer',
            ],
            'body' => 'required|string|max:' . (int) config('groups.messaging.max_message_length', 5000),
            'send_at' => 'required|string|max:40',
            GroupPostFormRequest::UPLOAD_KEY => 'prohibited',
            GroupPostFormRequest::VIDEO_UPLOAD_KEY => 'prohibited',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkSendAt($v));
    }

    public function messages(): array
    {
        return [
            'about_membership_id.required_if' =>
                'A participant-scoped conversation must name the membership of the member it concerns.',
            'about_membership_id.prohibited_unless' =>
                'Only a participant-scoped conversation may name a member it concerns.',
            GroupPostFormRequest::UPLOAD_KEY . '.prohibited' => 'A scheduled conversation is text only for now.',
            GroupPostFormRequest::VIDEO_UPLOAD_KEY . '.prohibited' => 'A scheduled conversation is text only for now.',
        ];
    }
}
