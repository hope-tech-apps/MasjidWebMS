<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;
use App\Support\PerformanceLevel;
use Illuminate\Validation\Rule;

/**
 * Saving a report card's marks and comment.
 *
 * The whole card in ONE request, for the same reason the register and the
 * gradebook are: a teacher fills one in on a laptop that may lose its
 * connection, and sixteen independent PUTs would leave a document half-written
 * with no way to tell which half.
 *
 * `level` is `nullable` and that is load-bearing. NULL means "not assessed this
 * quarter" — a real and necessary thing to say about a child who joined in week
 * eight — so it must be a value the payload can carry, never a field the client
 * omits and the server guesses at.
 *
 * WHAT IS DELIBERATELY NOT IN THIS PAYLOAD: `subject`, `criterion`, `published_at`.
 * The criteria come from the template when the card is prepared and are then
 * fixed for that document (see the migration on why they are stored as text);
 * letting a save rename them would let one request rewrite what a different
 * child's card claimed to assess. Publication is its own verb.
 */
class SaveReportCardRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'marks' => ['sometimes', 'array', 'max:200'],
            'marks.*.id' => ['required', 'integer'],
            'marks.*.level' => ['nullable', Rule::in(PerformanceLevel::ALL)],
            'marks.*.comment' => [
                'nullable', 'string',
                'max:' . (int) config('groups.report_cards.max_comment_length', 1000),
            ],
            'teacher_comment' => [
                'nullable', 'string',
                'max:' . (int) config('groups.report_cards.max_teacher_comment_length', 4000),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'marks.*.level.in' => 'A report card is marked on the four performance levels, 4 down to 1.',
        ];
    }
}
