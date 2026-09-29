<?php

namespace App\Http\Requests\Family;

use App\Http\Requests\BaseFormRequest;

/**
 * "I have seen these stories" — the ids of the class stories the Story tab is
 * showing right now (T-002.3, owner 2026-09-29). Nothing else: the contact comes
 * from the token, the school and class from the URL.
 *
 * Bounded by `groups.story_reads.max_ids_per_request`, so one request cannot be
 * a scan of a whole term. Ids that are not stories of this class are not an
 * error here; the controller ignores them (a foreign id must not be an oracle).
 *
 * A FormRequest, not an inline validate(): BaseFormRequest throws
 * HttpResponseException, which the app-wide JSON renderer returns verbatim.
 */
class MarkStoriesSeenRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'post_ids' => ['required', 'array', 'min:1', 'max:'.(int) config('groups.story_reads.max_ids_per_request', 50)],
            'post_ids.*' => ['integer', 'min:1'],
        ];
    }
}
