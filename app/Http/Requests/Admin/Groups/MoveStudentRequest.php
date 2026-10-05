<?php

namespace App\Http\Requests\Admin\Groups;

/**
 * Move a student to another class.
 *
 * The same shape as the read before it, plus what the office typed and what the
 * dialog showed. NO BOOLEANS: the admin SPA sends form-encoded bodies, where a
 * boolean arrives as the string "true".
 */
class MoveStudentRequest extends PreviewMoveRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            // Key absent keeps the grade; key present sets it (empty clears it).
            'grade_label' => 'sometimes|nullable|string|max:32',
            // What the dialog showed. When the server decides something else
            // under its locks, nothing is moved and the office is asked to
            // read again: the path, the first day in the new class and, on a
            // return, the joining day.
            'expected_path' => 'nullable|in:left_and_started,returned',
            'expected_first_day' => 'nullable|date_format:Y-m-d',
            'expected_joined_on' => 'nullable|date_format:Y-m-d',
            // The consent result the dialog showed, as the preview's own short
            // string of counts (`expected_consent`): a consent recorded or
            // withdrawn between the read and the tap changes it.
            'expected_consent' => 'nullable|string|max:32',
            // The rule for Manara Bucks the dialog showed. It is about the two
            // classes and the clock, never about a child. Handed to the move as
            // it comes; the preview answers null for it until a move can carry
            // a balance.
            'expected_bucks_rule' => 'nullable|in:move,from_ended,to_ended',
        ];
    }
}
