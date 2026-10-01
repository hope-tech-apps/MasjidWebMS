<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use App\Support\LineEndings;

/**
 * Change the words of a message already sent in a conversation (W7, 2026-10-01).
 *
 * The BODY only, at the ceiling sending has (`config('groups.messaging.
 * max_message_length')`, the one content rule creating applies). Nothing else of
 * a sent message is editable: not its photos or videos, not who wrote it, not
 * the conversation it is in. A client that sends any of those believes it
 * changed something, and answering 200 while ignoring them would be a silent
 * success, so each is refused by name, as `send_at` is on a reply.
 *
 * Extends BaseFormRequest, NOT the story/message upload requests: an edit has no
 * upload bag and must not inherit those rules.
 *
 * Whether the body may be EMPTY depends on the message (a photo-only message
 * keeps an empty body; a text message may not lose its words), and a request
 * cannot see the database, so the controller decides that one. WHO may edit is
 * not decided here either: the route's write gate, GroupAudience's read gate and
 * the author check, all in GroupThreadsController::updateMessage.
 */
class UpdateGroupMessageRequest extends BaseFormRequest
{
    /** Fields of a sent message that an edit may never carry. */
    private const REFUSED = [
        'images', 'videos', 'subject', 'scope', 'send_at', 'send_now',
        'author_user_id', 'author_contact_id', 'masjid_id', 'group_thread_id',
    ];

    /**
     * One line ending, as sending has (LineEndings): the message was stored with "\n", and
     * an edit that arrives with "\r\n" must not read as a change of words.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(LineEndings::normalised($this, ['body']));
    }

    public function rules(): array
    {
        $rules = [
            // PRESENT, not merely nullable: an edit that does not name the words at all
            // must not be read as "empty them". A photo message may lose its caption only
            // when the request says so (`body` sent, and empty).
            'body' => 'present|nullable|string|max:' . (int) config('groups.messaging.max_message_length', 5000),
        ];

        foreach (self::REFUSED as $field) {
            $rules[$field] = 'prohibited';
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = ['body.present' => 'Send the words of the message.'];

        foreach (self::REFUSED as $field) {
            $messages[$field . '.prohibited'] = 'Only the words of a sent message can be edited.';
        }

        return $messages;
    }
}
