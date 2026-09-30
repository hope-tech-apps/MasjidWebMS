<?php

namespace App\Http\Requests\ClassStore;

use App\Http\Requests\BaseFormRequest;
use App\Support\ClassStore;

/**
 * A teacher giving one student one prize (T-003.4).
 *
 * `request_id` is REQUIRED: one value per WRITE, minted by the screen (a UUID) and kept until
 * the write has a definitive answer, so a double-tap or a retry after a dropped response
 * sends the same one and the server treats it as a replay of the first, not a second
 * deduction. It used to be optional, which let a client that sent none double-charge a child
 * on flaky school wifi; a write without one is now a 422. Whose prize it may be, and whether
 * the student can afford it, is ClassStore's decision, not this request's.
 */
class RedeemPrizeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'prize_id' => ['required', 'integer', 'min:1'],
            'request_id' => ['required', 'string', 'regex:'.ClassStore::REQUEST_ID_PATTERN],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('groups.bucks.max_note_length', 255)],
        ];
    }
}
