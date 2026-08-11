<?php

namespace App\Http\Requests\Admin\Groups;

/**
 * Edit a post already on a group's feed.
 *
 * `sometimes` throughout, so a partial edit ("fix the typo in the body") cannot
 * blank the title or clear the retention window it did not mention. Images sent
 * with an edit are ADDED to the post; removing one is a deletion of that
 * attachment, not an edit of the post, and this slice does not pretend
 * otherwise.
 */
class UpdateGroupPostRequest extends GroupPostFormRequest
{
    public function rules(): array
    {
        return array_merge([
            'title' => 'sometimes|nullable|string|max:255',
            'body' => 'sometimes|required|string|max:' . (int) config('groups.feed.max_body_length', 5000),
            'retained_until' => 'sometimes|nullable|date',
        ], $this->imageRules());
    }
}
