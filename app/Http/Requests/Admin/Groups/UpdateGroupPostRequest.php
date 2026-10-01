<?php

namespace App\Http\Requests\Admin\Groups;

use Illuminate\Contracts\Validation\Validator;

/**
 * Edit a post already on a group's feed.
 *
 * `sometimes` throughout, so a partial edit ("fix the typo in the body") cannot
 * blank the title or clear the retention window it did not mention. Images sent
 * with an edit are ADDED to the post; removing one is a deletion of that
 * attachment, not an edit of the post, and this slice does not pretend
 * otherwise.
 *
 * `send_at` (a new school-clock time) and `send_now` move a story that has NOT gone
 * out yet (T-002.4); the controller refuses either on a story that is already
 * published, because pulling one back after families have read it is not a schedule
 * change. They are exclusive: one request that says both has no meaning.
 */
class UpdateGroupPostRequest extends GroupPostFormRequest
{
    use ValidatesSendAt;

    /**
     * The SPA posts form-encoded, so a checkbox arrives as the STRING "true". Laravel's
     * `boolean` rule refuses that, so it is coerced here (`.claude/rules/shipping.md`).
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->has('send_now')) {
            $this->merge([
                'send_now' => filter_var($this->input('send_now'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return array_merge([
            'title' => 'sometimes|nullable|string|max:255',
            'body' => 'sometimes|required|string|max:' . (int) config('groups.feed.max_body_length', 5000),
            'retained_until' => 'sometimes|nullable|date',
            'send_at' => 'sometimes|nullable|string|max:40',
            'send_now' => 'sometimes|boolean',
        ], $this->mediaRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->checkSendAt($v);

            if ($this->boolean('send_now') && $this->filled('send_at')) {
                $v->errors()->add('send_now', 'Choose either a time or send now, not both.');
            }
        });
    }
}
