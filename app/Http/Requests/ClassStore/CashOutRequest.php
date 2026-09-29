<?php

namespace App\Http\Requests\ClassStore;

use App\Http\Requests\BaseFormRequest;
use App\Support\ClassStore;

/**
 * A teacher paying one student's bucks out as paper notes (T-003.4). BUILT AND OFF: the
 * feature refuses in ClassStore while the school's `paper_bucks_enabled` is false, whatever
 * this request says.
 */
class CashOutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
            'request_id' => ['sometimes', 'nullable', 'string', 'regex:'.ClassStore::REQUEST_ID_PATTERN],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('groups.bucks.max_note_length', 255)],
        ];
    }
}
