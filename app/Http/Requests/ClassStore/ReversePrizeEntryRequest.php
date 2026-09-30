<?php

namespace App\Http\Requests\ClassStore;

use App\Http\Requests\BaseFormRequest;

/**
 * A teacher correcting a prize given or Bucks paid out (T-003.4): the only way to undo a ledger
 * entry, and it is a NEW entry, never an edit. The optional note says why.
 */
class ReversePrizeEntryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('groups.bucks.max_note_length', 255)],
        ];
    }
}
