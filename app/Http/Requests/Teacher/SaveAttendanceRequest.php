<?php

namespace App\Http\Requests\Teacher;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saving a class register for ONE day, in one request.
 *
 * The whole class in a single call is deliberate, not a convenience. A teacher
 * marks a register on a phone in a doorway on a bad connection; twelve
 * independent PUTs would leave the register half-written whenever one of them
 * failed, with no way for the teacher to tell which. One request either records
 * the day or does not.
 *
 * Authorization is NOT here. It is structural: this request only ever reaches a
 * route inside routes/teacher.php's `teacher.leads` group, so the caller already
 * leads this class. The controller still resolves every membership WITHIN the
 * group, so a well-formed body naming another class's student resolves to a 422
 * rather than writing across the roster.
 */
class SaveAttendanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The day the class MET — not necessarily today. A teacher writing up
            // Tuesday on Thursday is ordinary. Bounded in the future because a
            // register for a class that has not happened yet is a typo, not a
            // plan; `today` is evaluated in the app timezone.
            'session_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],

            'marks' => ['required', 'array', 'min:1', 'max:200'],
            'marks.*.membership_id' => ['required', 'integer'],
            'marks.*.status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'marks.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'session_date.before_or_equal' => 'You cannot take a register for a day that has not happened yet.',
            'marks.*.status.in' => 'That is not a mark this register understands.',
        ];
    }
}
