<?php

namespace App\Http\Requests\Admin\Groups;

/**
 * Correcting a recitation that is already recorded (HifzEntriesController::correct).
 *
 * The same line as a new record, checked by the same rules, so a corrected range
 * is held to the muṣḥaf exactly as a recorded one is: real āyāt, running
 * forwards, "whole surah" filled in by the server.
 *
 * Two differences from StoreHifzEntryRequest:
 *
 *  - NO `membership_id`. A correction cannot move a recitation to another
 *    student; the entry in the address says whose it is.
 *  - `note` must be PRESENT (blank clears it), the rule every note in this
 *    module follows: a request that does not mention the note is not allowed to
 *    erase one by leaving it out.
 *
 * `recited_at` stays optional and means "the day was changed": absent, the
 * entry keeps the moment it was heard.
 */
class CorrectHifzEntryRequest extends StoreHifzEntryRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['membership_id']);

        $rules['note'] = 'present|nullable|string|max:' . (int) config('groups.hifz.max_note_length', 1000);

        return $rules;
    }
}
