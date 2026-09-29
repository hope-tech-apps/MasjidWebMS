<?php

namespace App\Http\Requests\Admin\Groups;

/**
 * Post one message into a group thread (T-005c) — text, photos, or both.
 *
 * The text keeps the configured ceiling (config/groups.php
 * `messaging.max_message_length`). Photos ride in the same top-level `images`
 * bag as the class story and are held to the same allowlist and size ceiling
 * (`config('groups.media')`, sniffed from the bytes) — which is why this extends
 * GroupPostFormRequest rather than restating the rules: one definition of what
 * a photo of a child may be, wherever it is sent. A message needs one or the
 * other; an empty message is refused.
 *
 * WHO may post is not decided here: the admin route requires
 * `manage contacts` (the teacher route, `teacher.leads`), and
 * GroupThreadsController additionally requires that the caller may READ the
 * thread (App\Support\GroupAudience) — a conversation is only writable by
 * people who are in it.
 */
class StoreGroupMessageRequest extends GroupPostFormRequest
{
    public function rules(): array
    {
        return array_merge([
            // required_without_ALL, not required_without: a message carrying
            // only a video sends no `images` bag, and `required_without:images`
            // would have refused it as empty.
            'body' => 'nullable|required_without_all:' . self::UPLOAD_KEY . ',' . self::VIDEO_UPLOAD_KEY
                . '|string|max:' . (int) config('groups.messaging.max_message_length', 5000),
            // A reply is sent when it is written (S11: only NEW conversations may be
            // scheduled). A client that sends a time here believes it scheduled
            // something; answering 201 and sending at once would be a silent success
            // of the worst kind, so the field is refused.
            'send_at' => 'prohibited',
            'send_now' => 'prohibited',
        ], $this->mediaRules());
    }

    protected function uploadNoun(): string
    {
        return 'message';
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'body.required_without_all' => 'Write a message or attach a photo or video.',
            'send_at.prohibited' => 'A reply cannot be scheduled; only a new conversation can.',
            'send_now.prohibited' => 'A reply cannot be scheduled; only a new conversation can.',
        ]);
    }
}
