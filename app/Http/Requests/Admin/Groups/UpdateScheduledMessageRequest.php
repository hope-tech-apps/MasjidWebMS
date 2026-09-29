<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * Edit a scheduled conversation that has not gone out (T-002.4).
 *
 * Subject, words and time only. WHO it goes to (scope, the child it concerns) is not
 * editable: changing the audience of a written message is a different message, and the
 * way to do it is cancel and write another. A new `send_at`, or `send_now`, is what
 * puts a FAILED item back in the queue.
 *
 * `send_now` sets the time to this moment and lets the sweep pick it up within the
 * minute; it is exclusive with `send_at`. Coerced from the STRING "true" a form-encoded
 * client sends (shipping.md).
 */
class UpdateScheduledMessageRequest extends BaseFormRequest
{
    use ValidatesSendAt;

    protected function prepareForValidation(): void
    {
        if ($this->has('send_now')) {
            $this->merge([
                'send_now' => filter_var($this->input('send_now'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'subject' => 'sometimes|required|string|max:255',
            'body' => 'sometimes|required|string|max:' . (int) config('groups.messaging.max_message_length', 5000),
            'send_at' => 'sometimes|nullable|string|max:40',
            'send_now' => 'sometimes|boolean',
            GroupPostFormRequest::UPLOAD_KEY => 'prohibited',
            GroupPostFormRequest::VIDEO_UPLOAD_KEY => 'prohibited',
        ];
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

    public function messages(): array
    {
        return [
            GroupPostFormRequest::UPLOAD_KEY . '.prohibited' => 'A scheduled conversation is text only for now.',
            GroupPostFormRequest::VIDEO_UPLOAD_KEY . '.prohibited' => 'A scheduled conversation is text only for now.',
        ];
    }
}
