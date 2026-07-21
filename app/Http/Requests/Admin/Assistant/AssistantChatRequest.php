<?php

namespace App\Http\Requests\Admin\Assistant;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates one assistant turn. Uses BaseFormRequest so a bad payload renders as
 * a 422 rather than a 500 (the app's JSON handler only preserves
 * HttpResponseException / HttpExceptionInterface).
 *
 * The image cap is deliberate: high-resolution images can cost ~4,800 input
 * tokens each, so we bound what an admin can attach and downsample server-side
 * before sending it on.
 */
class AssistantChatRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'], // KB
            // Prior turns, replayed so the conversation has memory. Capped to keep
            // the request (and the bill) bounded.
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history'],
        ];
    }
}
