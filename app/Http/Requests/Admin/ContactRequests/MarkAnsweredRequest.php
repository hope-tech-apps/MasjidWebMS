<?php

namespace App\Http\Requests\Admin\ContactRequests;

use App\Http\Requests\BaseFormRequest;

/**
 * The manual answered/unanswered toggle on a contact message (PLAN T-042d).
 *
 * Staff answer plenty of messages by picking up the phone, and until they can
 * say so the inbox cannot tell "dealt with" from "nobody has looked at it" —
 * which is the whole reason two people end up answering the same person. So the
 * flag is settable without sending anything, and clearable again: triage is a
 * LABEL, not a state machine, the same reading .claude/rules/appointments.md
 * records for appointment statuses. There is no transition guard here on
 * purpose, and one must not be added.
 *
 * `boolean` and not `in:0,1`: Laravel's rule accepts 1/0/"1"/"0"/true/false, and
 * the SPA sends the STRINGS "1"/"0" through URLSearchParams. It does NOT accept
 * the strings "true"/"false" — sending those is what blocked every live
 * Jummah-lunch order once. See the store's markAnswered().
 */
class MarkAnsweredRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'answered' => 'required|boolean',
        ];
    }
}
