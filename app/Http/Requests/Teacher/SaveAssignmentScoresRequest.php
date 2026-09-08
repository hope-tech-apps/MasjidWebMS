<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;
use App\Models\AssignmentScore;
use Illuminate\Validation\Rule;

/**
 * Marking a whole class's work in ONE request.
 *
 * The same reasoning as the register (SaveAttendanceRequest): a teacher marks on
 * a phone on a bad connection, and twelve independent PUTs would leave the
 * gradebook half-written with no way to tell which half. One request either
 * records the marking or does not.
 *
 * WHAT IS DELIBERATELY NOT IN THIS PAYLOAD: `points_possible`, `title`,
 * `assigned_on`. Letting a scoring call carry the assignment's maximum would let
 * one teacher's typo silently restate every other child's mark in the same
 * request. The assignment is edited by its own verb.
 *
 * `points_earned` is `required_if` scored and `prohibited_unless` scored, so the
 * two halves cannot disagree: a `missing` row carrying 7 points, or a `scored`
 * row carrying nothing, are both refused rather than quietly normalised.
 */
class SaveAssignmentScoresRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'scores' => ['required', 'array', 'min:1', 'max:200'],
            'scores.*.membership_id' => ['required', 'integer'],
            'scores.*.status' => ['required', Rule::in(AssignmentScore::STATUSES)],
            'scores.*.points_earned' => [
                'nullable', 'numeric', 'min:0',
                'required_if:scores.*.status,' . AssignmentScore::STATUS_SCORED,
                'prohibited_unless:scores.*.status,' . AssignmentScore::STATUS_SCORED,
            ],
            'scores.*.note' => [
                'nullable', 'string',
                'max:' . (int) config('groups.gradebook.max_note_length', 1000),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'scores.*.status.in' => 'That is not a mark this gradebook understands.',
            'scores.*.points_earned.required_if' => 'A scored piece of work needs a mark.',
            'scores.*.points_earned.prohibited_unless' => 'Only scored work carries a mark.',
        ];
    }
}
