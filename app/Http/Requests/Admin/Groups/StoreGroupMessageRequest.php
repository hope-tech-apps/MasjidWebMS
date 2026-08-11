<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

/**
 * Post one message into a group thread (T-005c).
 *
 * Body text only, with the same configured ceiling as a feed post
 * (config/groups.php `messaging.max_message_length`): a thread message is a
 * note to a parent or a teacher, not a document store. Attachments are
 * deliberately absent from this slice — the feed owns media, and a thread file
 * would need the whole private-disk pipeline, not a smaller copy of it.
 *
 * WHO may post is not decided here: the route requires `manage contacts`, and
 * GroupThreadsController additionally requires that the caller may READ the
 * thread (App\Support\GroupAudience) — a conversation is only writable by
 * people who are in it.
 */
class StoreGroupMessageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'body' => 'required|string|max:' . (int) config('groups.messaging.max_message_length', 5000),
        ];
    }
}
