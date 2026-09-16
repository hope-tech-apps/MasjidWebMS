<?php

namespace App\Http\Requests\Admin\Arabic;

use App\Http\Requests\BaseFormRequest;

/**
 * One teacher's note about one child's Arabic on one day.
 *
 * Extends BaseFormRequest, not FormRequest: a rejection from a bare
 * FormRequest escapes this application's JSON renderer as a raw
 * ValidationException and comes back a 500 rather than the legacy
 * `{status:'failed'}` 422 every client here expects. That has bitten this
 * repository twice.
 */
class SaveDailyNoteRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        // Authorisation is the route's: `auth:sanctum` + `teacher` +
        // `teacher.leads` for the teacher realm, and admin + tenant +
        // permission for the console. A FormRequest saying true here is not a
        // hole — it is the deliberate division this codebase already uses, so
        // that who-may-act lives in one place instead of two that can disagree.
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * The day the note is ABOUT, which is not necessarily today: a
             * teacher writing up Thursday's lesson on Friday morning is the
             * ordinary case, not an edge one.
             *
             * Bounded in the future because a note about a lesson that has not
             * happened is a typo every time — a mis-keyed year is the usual
             * way it arrives — and an accepted one would sit at the top of the
             * child's history until somebody noticed.
             */
            'session_date' => ['required', 'date', 'before_or_equal:today'],

            /*
             * Required, and deliberately not nullable. Clearing a day's note is
             * a DELETE, which says what happened; a PUT with an empty body
             * would leave a row asserting that a teacher wrote nothing, which
             * is a different and untrue claim. Absence of a row means nobody
             * wrote — the same rule the register and the gradebook hold.
             */
            'note' => ['required', 'string', 'max:' . (int) config('groups.arabic.max_daily_note_length', 2000)],
        ];
    }

    public function messages(): array
    {
        return [
            'session_date.before_or_equal' => 'A daily note cannot be dated in the future.',
            'note.required' => 'Write the note, or delete the day’s note to remove it.',
        ];
    }
}
