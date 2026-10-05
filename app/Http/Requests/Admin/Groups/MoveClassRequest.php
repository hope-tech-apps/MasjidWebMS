<?php

namespace App\Http\Requests\Admin\Groups;

use App\Support\RosterClassMove;

/**
 * Move the chosen students of a class to another class.
 *
 * THE REQUEST NAMES THE ROSTER ROWS THE OFFICE WAS SHOWN, each with what it
 * was shown for that student. An absent list never means "everyone in the
 * class when the request lands": that is the defect the bulk confirm was
 * rebuilt to remove (ConfirmGroupMembershipsRequest), and here it would move
 * a child the office had left unticked, or one enrolled a moment ago.
 *
 * NO BOOLEANS, AND EVERY VALUE A STRING. The admin SPA sends form-encoded
 * bodies, so the rows arrive as `students[0][membership_id]`, a number is a
 * string and a blank joining day or grade is an empty string.
 *
 * The ids are only shape-checked. Whether each is a current student of this
 * class is settled by App\Support\RosterClassMove against the tenant-scoped
 * class: an `exists:` rule here would confirm the existence of another
 * organisation's row in a validation message.
 */
class MoveClassRequest extends PreviewClassMoveRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // No choice is made for the office: the dialog pre-selects none.
            'grade_mode' => 'required|in:keep,set,up',
            'grade_label' => 'required_if:grade_mode,set|nullable|string|max:32',
            // The rule for Manara Bucks the dialog showed. It is about the two
            // classes and the clock, never about a child. Handed to every move
            // as it comes; the preview answers null for it until a move can
            // carry a balance.
            'expected_bucks_rule' => 'nullable|in:move,from_ended,to_ended',
            'students' => 'required|array|min:1|max:'.RosterClassMove::MAX_STUDENTS,
            'students.*.membership_id' => 'required|integer|distinct',
            // What the dialog showed for this student. When the server would
            // now decide something else, nothing is moved and the office is
            // asked to look again.
            'students.*.expected_path' => 'required|in:left_and_started,returned',
            'students.*.expected_first_day' => 'required|date_format:Y-m-d',
            'students.*.expected_joined_on' => 'nullable|date_format:Y-m-d',
            'students.*.expected_consent' => 'required|string|max:32',
            // The grade the preview said the student would hold (`grade_after`).
            'students.*.expected_grade' => 'nullable|string|max:32',
        ]);
    }

    public function messages(): array
    {
        return [
            'students.required' => 'Name the students to move. A class is moved one student at a time, and only the '
                .'students you ticked.',
            'students.min' => 'Name the students to move.',
            'students.max' => 'Move up to '.RosterClassMove::MAX_STUDENTS.' students at a time.',
            'grade_mode.required' => 'Choose what happens to grades.',
            'grade_label.required_if' => 'Type the grade to give everyone.',
        ];
    }
}
