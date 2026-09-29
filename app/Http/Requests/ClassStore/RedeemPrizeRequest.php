<?php

namespace App\Http\Requests\ClassStore;

use App\Http\Requests\BaseFormRequest;
use App\Support\ClassStore;

/**
 * A teacher giving one student one prize (T-003.4).
 *
 * `request_id` is one value per CLICK, minted by the screen (a UUID): a double-tap or a retry
 * after a dropped response sends the same one, and the server treats it as a replay of the
 * first, not a second deduction. Optional, so a client that cannot mint one still works,
 * just without that protection. Whose prize it may be, and whether the student can afford
 * it, is ClassStore's decision, not this request's.
 */
class RedeemPrizeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'prize_id' => ['required', 'integer', 'min:1'],
            'request_id' => ['sometimes', 'nullable', 'string', 'regex:'.ClassStore::REQUEST_ID_PATTERN],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('groups.bucks.max_note_length', 255)],
        ];
    }
}
