<?php

namespace App\Http\Requests\Admin\Groups;

use Illuminate\Contracts\Validation\Validator;

/**
 * Publish a post to a group's private feed.
 *
 * `retained_until` is accepted but optional: when it is absent GroupPost's
 * creating hook stamps the configured retention window, so a post is bounded
 * even when nobody thought about it. `after_or_equal:today` because a retention
 * date already in the past would mean "purge this the moment it is written",
 * which is a mistake rather than an instruction.
 *
 * `send_at` (T-002.4) schedules the story: the school's own wall clock, in the
 * future, at most 30 days ahead (ValidatesSendAt). Absent means "now".
 */
class StoreGroupPostRequest extends GroupPostFormRequest
{
    use ValidatesSendAt;

    public function rules(): array
    {
        return array_merge([
            'title' => 'nullable|string|max:255',
            'body' => 'required|string|max:' . (int) config('groups.feed.max_body_length', 5000),
            'retained_until' => 'nullable|date|after_or_equal:today',
            'send_at' => 'nullable|string|max:40',
        ], $this->mediaRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkSendAt($v));
    }
}
